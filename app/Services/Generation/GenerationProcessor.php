<?php

namespace App\Services\Generation;

use App\Enums\ChunkStatus;
use App\Enums\GenerationMode;
use App\Enums\GenerationStage;
use App\Enums\GenerationStatus;
use App\Events\GenerationFailed;
use App\Events\GenerationFinished;
use App\Exceptions\AudioProcessingException;
use App\Models\Generation;
use App\Models\GenerationChunk;
use App\Services\Audio\AudioProcessor;
use App\Services\Audio\NarrationMixer;
use App\Services\HeyGen\HeyGenException;
use App\Services\HeyGen\HeyGenService;
use App\Services\PronunciationService;
use App\Services\SettingsService;
use App\Services\Text\SpeakerParser;
use App\Services\Text\TextChunker;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Throwable;
use ZipArchive;

/**
 * Runs one generation end to end inside a queue worker:
 * preparing -> splitting -> generating (sequential chunks) -> processing -> merging -> completed.
 */
class GenerationProcessor
{
    /** No-charge HeyGen model (instant voices only, daily limits). */
    public const FREE_MODEL = 'heygen-voice-1-turbo-free';

    public function __construct(
        private readonly HeyGenService $heygen,
        private readonly AudioProcessor $audio,
        private readonly TextChunker $chunker,
        private readonly SettingsService $settings,
        private readonly SpeakerParser $speakers,
        private readonly PronunciationService $pronunciations,
        private readonly NarrationMixer $mixer,
    ) {}

    public function process(int $generationId): void
    {
        // Atomic claim: only one worker can move a generation from pending to processing.
        $claimed = Generation::query()
            ->whereKey($generationId)
            ->where('status', GenerationStatus::Pending)
            ->update([
                'status' => GenerationStatus::Processing,
                'stage' => GenerationStage::Preparing,
                'progress' => 1,
                'started_at' => now(),
                'error_message' => null,
                'updated_at' => now(),
            ]);

        if ($claimed === 0) {
            Log::info('Generation not claimed (already running, finished or cancelled).', ['generation' => $generationId]);

            return;
        }

        $generation = Generation::query()->findOrFail($generationId);

        try {
            $this->run($generation);
        } catch (HeyGenException|AudioProcessingException $e) {
            $this->fail($generation, $e->getMessage());
        } catch (Throwable $e) {
            Log::error('Generation crashed', ['generation' => $generationId, 'exception' => $e]);
            $this->fail($generation, 'Unexpected error: '.$e->getMessage());
        }
    }

    private function run(Generation $generation): void
    {
        // ---- Preparing: fail fast before spending any HeyGen credits.
        if (! $this->heygen->hasApiKey()) {
            throw HeyGenException::missingApiKey();
        }
        if (! $this->audio->isAvailable()) {
            throw new AudioProcessingException('FFmpeg/ffprobe is not installed or not executable. Install FFmpeg (see README) and retry.');
        }

        // ---- Splitting
        $this->stage($generation, GenerationStage::Splitting, 3);
        $this->ensureChunks($generation);

        // ---- Generating
        $this->stage($generation, GenerationStage::Generating, 5);
        $disk = $this->disk();

        foreach ($generation->chunks()->get() as $chunk) {
            if ($chunk->status === ChunkStatus::Completed && $chunk->audio_path && $disk->exists($chunk->audio_path)) {
                continue; // reuse audio from a previous attempt
            }

            if ($generation->isCancellationRequested()) {
                $this->cancel($generation);

                return;
            }

            $this->generateChunk($generation, $chunk);
            $this->updateChunkProgress($generation);
        }

        if ($generation->isCancellationRequested()) {
            $this->cancel($generation);

            return;
        }

        // ---- Processing
        $this->stage($generation, GenerationStage::Processing, 88);
        $chunks = $generation->chunks()->get();
        foreach ($chunks as $chunk) {
            if (! $chunk->audio_path || ! $disk->exists($chunk->audio_path)) {
                throw new AudioProcessingException("Audio for chunk #{$chunk->chunk_index} is missing.");
            }
        }

        // ---- Merging
        $this->stage($generation, GenerationStage::Merging, 92);
        $generation->mode === GenerationMode::Segments
            ? $this->finishSegments($generation, $chunks)
            : $this->finishContinuous($generation, $chunks);

        $generation->update([
            'status' => GenerationStatus::Completed,
            'stage' => GenerationStage::Completed,
            'progress' => 100,
            'completed_at' => now(),
            'error_message' => null,
        ]);

        GenerationFinished::dispatch($generation);
    }

    private function ensureChunks(Generation $generation): void
    {
        if ($generation->chunks()->exists()) {
            $generation->update(['total_chunks' => $generation->chunks()->count()]);

            return;
        }

        $max = min(
            (int) $this->settings->get('generation.chunk_max_chars'),
            (int) config('soundex.heygen.max_chars_per_request'),
        );

        // Character voices: each speaker turn is chunked separately so a chunk has exactly one voice.
        $voices = array_filter((array) $generation->speaker_voices);
        $turns = $voices === []
            ? [['speaker' => null, 'text' => $generation->script]]
            : $this->speakers->turns($generation->script, $voices, $generation->strip_speaker_labels);

        $index = 0;
        foreach ($turns as $turn) {
            $voice = $turn['speaker'] !== null ? ($voices[$turn['speaker']] ?? null) : null;
            foreach ($this->chunker->chunk($turn['text'], max(200, $max)) as $text) {
                $generation->chunks()->create([
                    'chunk_index' => $index++,
                    'text' => $text,
                    'speaker' => $voice['label'] ?? null,
                    'voice_id' => $voice['voice_id'] ?? null,
                    'voice_name' => $voice['voice_name'] ?? null,
                    'voice_mode' => $voice['voice_mode'] ?? null,
                    'status' => ChunkStatus::Pending,
                ]);
            }
        }

        if ($index === 0) {
            throw new HeyGenException('The script is empty.', HeyGenException::CATEGORY_INVALID);
        }

        $generation->update(['total_chunks' => $index]);
    }

    private function canFallBackToFreeModel(?string $voiceMode, ?string $model, HeyGenException $e): bool
    {
        return $e->errorCode === 'insufficient_credit'
            && $model !== self::FREE_MODEL
            && $voiceMode === 'instant'
            && config('soundex.heygen.free_model_fallback');
    }

    /** Request options for one chunk (its own voice, pronunciations and word timestamps). */
    private function chunkOptions(Generation $generation, GenerationChunk $chunk, string $model): array
    {
        $voiceMode = $chunk->voice_mode ?? $generation->voice_mode;

        $dictionary = [];
        if ($generation->use_pronunciations && ! empty($generation->pronunciation_dictionary)
            && $this->pronunciations->isSupported($voiceMode, $model)) {
            $dictionary = $this->pronunciations->forText($generation->pronunciation_dictionary, $chunk->text);
        }

        return [
            'model' => $model,
            'language' => $generation->language,
            'output_format' => $generation->output_format,
            'mp3_bitrate' => $generation->mp3_bitrate,
            // speed is only honoured by professional clones.
            'speed' => $voiceMode === 'professional' ? $generation->speed : null,
            'pronunciation_dictionary' => $dictionary,
            'with_timestamps' => true,
        ];
    }

    private function generateChunk(Generation $generation, GenerationChunk $chunk): void
    {
        $maxAttempts = max(1, (int) config('soundex.heygen.max_attempts'));
        $chunk->update(['status' => ChunkStatus::Processing, 'error_message' => null]);

        $voiceId = $chunk->voice_id ?? $generation->voice_id;
        $voiceMode = $chunk->voice_mode ?? $generation->voice_mode;
        $model = $generation->model;

        $attempt = 0;
        while (true) {
            $attempt++;
            $chunk->increment('attempts');

            try {
                $options = $this->chunkOptions($generation, $chunk, $model);
                $result = $this->heygen->synthesize($voiceId, $chunk->text, $options);

                $relative = GenerationService::directory($generation).sprintf('/chunks/%04d.%s', $chunk->chunk_index, $generation->output_format);
                $this->heygen->downloadAudio($result->audioUrl, $this->disk()->path($relative));

                $duration = $this->safeDuration($this->disk()->path($relative), $result->durationSeconds);

                $chunk->update([
                    'status' => ChunkStatus::Completed,
                    'audio_path' => $relative,
                    'duration_seconds' => $duration,
                    'heygen_request_id' => $result->requestId,
                    'words' => $result->words ?: null,
                    'error_message' => null,
                ]);

                return;
            } catch (HeyGenException $e) {
                // Out of paid credits: instant voices can still speak on the free model.
                if ($this->canFallBackToFreeModel($voiceMode, $model, $e)) {
                    $model = self::FREE_MODEL;
                    $generation->update(['model' => self::FREE_MODEL]);
                    $chunk->update(['error_message' => 'No paid HeyGen credits left; switched to the free Turbo model.']);
                    Log::notice('Falling back to free HeyGen model', ['generation' => $generation->id, 'chunk' => $chunk->chunk_index]);

                    continue;
                }

                $canRetry = $e->isRetryable() && $attempt < $maxAttempts;

                if (! $canRetry) {
                    $chunk->update(['status' => ChunkStatus::Failed, 'error_message' => $e->getMessage()]);
                    throw new HeyGenException(
                        'Chunk #'.($chunk->chunk_index + 1)." failed after {$attempt} attempt(s): ".$e->getMessage(),
                        $e->category, $e->httpStatus, $e->errorCode, previous: $e,
                    );
                }

                $wait = $this->backoffSeconds($attempt, $e->retryAfterSeconds);
                $chunk->update(['error_message' => "Attempt {$attempt} failed ({$e->getMessage()}). Retrying in {$wait}s."]);
                Log::notice('Retrying HeyGen chunk', ['generation' => $generation->id, 'chunk' => $chunk->chunk_index, 'wait' => $wait, 'category' => $e->category]);
                Sleep::for($wait)->seconds();

                if ($generation->isCancellationRequested()) {
                    $chunk->update(['status' => ChunkStatus::Cancelled]);

                    return;
                }
            }
        }
    }

    public function backoffSeconds(int $attempt, ?int $retryAfter): int
    {
        $max = (int) config('soundex.heygen.backoff_max_seconds');
        if ($retryAfter !== null && $retryAfter > 0) {
            return min($retryAfter, $max);
        }

        $base = (int) config('soundex.heygen.backoff_base_seconds');

        return (int) min($max, $base * (2 ** ($attempt - 1)) + random_int(0, max(1, $base)));
    }

    private function finishContinuous(Generation $generation, $chunks): void
    {
        $disk = $this->disk();
        $relative = GenerationService::directory($generation).'/narration.'.$generation->output_format;

        $this->audio->merge(
            $chunks->map(fn (GenerationChunk $c) => $disk->path($c->audio_path))->all(),
            $disk->path($relative),
            $this->audioOptions($generation),
        );

        $generation->update([
            'audio_path' => $relative,
            'voice_audio_path' => $relative,
            'duration_seconds' => $this->audio->probeDuration($disk->path($relative)),
            'progress' => 96,
        ]);

        $this->mixer->apply($generation);
    }

    private function finishSegments(Generation $generation, $chunks): void
    {
        $disk = $this->disk();
        $dir = GenerationService::directory($generation);
        $format = $generation->output_format;

        // Timeline preview: each clip starts at its original subtitle start time.
        $timelinePath = "{$dir}/timeline.{$format}";
        $this->audio->timeline(
            $chunks->map(fn (GenerationChunk $c) => ['path' => $disk->path($c->audio_path), 'start_ms' => (int) $c->start_ms])->all(),
            $disk->path($timelinePath),
            $this->audioOptions($generation),
        );

        $report = [];
        $sorted = $chunks->sortBy('start_ms')->values();
        foreach ($sorted as $i => $c) {
            $available = $c->availableSeconds();
            $generated = (float) $c->duration_seconds;
            $nextStart = isset($sorted[$i + 1]) ? $sorted[$i + 1]->start_ms / 1000 : null;
            $report[] = [
                'segment' => $c->chunk_index + 1,
                'subtitles' => implode(',', (array) $c->subtitle_indices),
                'start' => self::timecode((int) $c->start_ms),
                'end' => self::timecode((int) $c->end_ms),
                'available_seconds' => round((float) $available, 3),
                'generated_seconds' => round($generated, 3),
                'difference_seconds' => round($generated - (float) $available, 3),
                'fits_subtitle_window' => $generated <= (float) $available,
                'overlaps_next_segment' => $nextStart !== null && ($c->start_ms / 1000 + $generated) > $nextStart,
                'file' => sprintf('clips/%03d_%s.%s', $c->chunk_index + 1, str_replace([':', ','], ['-', '.'], self::timecode((int) $c->start_ms)), $format),
                'text' => $c->text,
            ];
        }

        $bundlePath = "{$dir}/segments.zip";
        $zip = new ZipArchive;
        if ($zip->open($disk->path($bundlePath), ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new AudioProcessingException('Could not create the segments ZIP file.');
        }
        foreach ($chunks as $c) {
            $entry = collect($report)->firstWhere('segment', $c->chunk_index + 1);
            $zip->addFile($disk->path($c->audio_path), $entry['file']);
        }
        $zip->addFile($disk->path($timelinePath), "timeline.{$format}");
        $zip->addFromString('timing_report.csv', $this->csv($report));
        $zip->addFromString('timing_report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $zip->addFromString('README.txt', "Each clip keeps its original subtitle timestamps in timing_report.csv.\n"
            ."Clips were NOT stretched or sped up. 'fits_subtitle_window' compares the measured clip length with the\n"
            ."original subtitle duration; timeline.{$format} places every clip at its original start time.\n");
        $zip->close();

        $generation->update([
            'audio_path' => $timelinePath,
            'voice_audio_path' => $timelinePath,
            'bundle_path' => $bundlePath,
            'timing_report' => $report,
            'duration_seconds' => $this->audio->probeDuration($disk->path($timelinePath)),
            'progress' => 96,
        ]);

        $this->mixer->apply($generation);
    }

    private function audioOptions(Generation $generation): array
    {
        return [
            'silence_ms' => $generation->silence_between_ms,
            'normalize' => $generation->normalize_loudness,
            'trim_silence' => $generation->trim_chunk_silence,
            'format' => $generation->output_format,
            'bitrate' => $generation->mp3_bitrate ?? 128000,
        ];
    }

    private function csv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF"); // UTF-8 BOM so spreadsheet apps show Khmer correctly
        fputcsv($handle, array_keys($rows[0] ?? ['segment' => null]), escape: '');
        foreach ($rows as $row) {
            $row = array_map(fn ($v) => is_bool($v) ? ($v ? 'yes' : 'no') : $v, $row);
            fputcsv($handle, $row, escape: '');
        }
        rewind($handle);

        return (string) stream_get_contents($handle);
    }

    public static function timecode(int $ms): string
    {
        return sprintf('%02d:%02d:%02d,%03d', intdiv($ms, 3600000), intdiv($ms % 3600000, 60000), intdiv($ms % 60000, 1000), $ms % 1000);
    }

    private function safeDuration(string $path, float $fallback): float
    {
        try {
            return $this->audio->probeDuration($path);
        } catch (AudioProcessingException) {
            return $fallback;
        }
    }

    private function updateChunkProgress(Generation $generation): void
    {
        $done = $generation->chunks()->where('status', ChunkStatus::Completed)->count();
        $total = max(1, $generation->total_chunks);

        $generation->update([
            'completed_chunks' => $done,
            'progress' => 5 + (int) floor(80 * $done / $total),
        ]);
    }

    private function stage(Generation $generation, GenerationStage $stage, int $progress): void
    {
        $generation->update(['stage' => $stage, 'progress' => max($generation->progress, $progress)]);
    }

    private function cancel(Generation $generation): void
    {
        $generation->chunks()->whereIn('status', [ChunkStatus::Pending, ChunkStatus::Processing])
            ->update(['status' => ChunkStatus::Cancelled]);

        $generation->update([
            'status' => GenerationStatus::Cancelled,
            'completed_at' => now(),
            'error_message' => 'Cancelled by user. Completed chunks were kept; use "Retry failed chunks" to resume.',
        ]);
    }

    private function fail(Generation $generation, string $message): void
    {
        $generation->chunks()->where('status', ChunkStatus::Processing)
            ->update(['status' => ChunkStatus::Failed, 'error_message' => 'Interrupted.']);

        $generation->update([
            'status' => GenerationStatus::Failed,
            'completed_at' => now(),
            'error_message' => mb_substr($message, 0, 2000),
            'completed_chunks' => $generation->chunks()->where('status', ChunkStatus::Completed)->count(),
        ]);

        GenerationFailed::dispatch($generation);
    }

    private function disk(): Filesystem
    {
        return Storage::disk(config('soundex.storage_disk'));
    }
}
