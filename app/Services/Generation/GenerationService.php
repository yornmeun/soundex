<?php

namespace App\Services\Generation;

use App\Enums\ChunkStatus;
use App\Enums\GenerationMode;
use App\Enums\GenerationStage;
use App\Enums\GenerationStatus;
use App\Jobs\ProcessGeneration;
use App\Models\Generation;
use App\Models\UploadedFile;
use App\Models\User;
use App\Models\VoiceProfile;
use App\Services\SettingsService;
use App\Services\Srt\SegmentBuilder;
use App\Services\Srt\SrtCue;
use App\Services\Text\TextStats;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class GenerationService
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly SegmentBuilder $segments,
        private readonly TextStats $stats,
    ) {}

    /**
     * Create a generation from validated wizard input and queue it.
     *
     * @param  array<string,mixed>  $data  validated StoreGenerationRequest data
     */
    public function create(User $user, array $data, VoiceProfile $voice, ?UploadedFile $upload): Generation
    {
        $mode = GenerationMode::from($data['mode']);

        $generation = DB::transaction(function () use ($user, $data, $voice, $upload, $mode) {
            $script = (string) $data['script'];
            $segmentRows = [];

            if ($mode === GenerationMode::Segments) {
                $segmentRows = $this->buildSegments($upload, $data);
                $script = implode("\n", array_column($segmentRows, 'text'));
            }

            $generation = Generation::query()->create([
                'user_id' => $user->id,
                'uploaded_file_id' => $upload?->id,
                'project_name' => $data['project_name'],
                'original_filename' => $upload?->original_filename,
                'script' => $script,
                'mode' => $mode,
                'voice_id' => $voice->voice_id,
                'voice_name' => $voice->name,
                'voice_mode' => $voice->mode,
                'language' => $data['language'] === 'auto' ? null : $data['language'],
                'model' => $data['model'],
                'output_format' => $data['output_format'],
                'mp3_bitrate' => $data['output_format'] === 'mp3' ? (int) $data['mp3_bitrate'] : null,
                'speed' => $voice->isProfessional() && isset($data['speed']) ? (float) $data['speed'] : null,
                'silence_between_ms' => (int) ($data['silence_between_ms'] ?? 0),
                'normalize_loudness' => (bool) ($data['normalize_loudness'] ?? false),
                'trim_chunk_silence' => (bool) ($data['trim_chunk_silence'] ?? false),
                'total_words' => $this->stats->wordCount($script),
                'status' => GenerationStatus::Pending,
                'stage' => GenerationStage::Queued,
                'progress' => 0,
            ]);

            foreach ($segmentRows as $i => $row) {
                $generation->chunks()->create([
                    'chunk_index' => $i,
                    'text' => $row['text'],
                    'start_ms' => $row['start_ms'],
                    'end_ms' => $row['end_ms'],
                    'subtitle_indices' => $row['subtitle_indices'],
                    'status' => ChunkStatus::Pending,
                ]);
            }

            if ($segmentRows !== []) {
                $generation->update(['total_chunks' => count($segmentRows)]);
            }

            return $generation;
        });

        ProcessGeneration::dispatch($generation->id)->afterCommit();

        return $generation;
    }

    /** Build segments from the stored cues, applying the user's edited texts. */
    public function buildSegments(?UploadedFile $upload, array $data): array
    {
        if (! $upload || empty($upload->cues)) {
            throw ValidationException::withMessages(['mode' => 'Subtitle-synchronized mode needs an uploaded SRT file.']);
        }

        $cues = array_map(fn ($c) => SrtCue::fromArray($c), $upload->cues);
        $rows = $this->segments->build(
            $cues,
            $data['segment_strategy'] ?? SegmentBuilder::STRATEGY_SENTENCE,
            min((int) $this->settings->get('generation.chunk_max_chars'), (int) config('kikitoons.heygen.max_chars_per_request')),
        );

        $edited = $data['segment_texts'] ?? null;
        if (is_array($edited)) {
            if (count($edited) !== count($rows)) {
                throw ValidationException::withMessages(['segment_texts' => 'The segment list is out of date. Please reload the segments.']);
            }
            foreach ($rows as $i => &$row) {
                $row['text'] = trim((string) $edited[$i]);
            }
            unset($row);
        }

        $rows = array_values(array_filter($rows, fn ($r) => $r['text'] !== ''));
        if ($rows === []) {
            throw ValidationException::withMessages(['segment_texts' => 'All segments are empty.']);
        }

        return $rows;
    }

    /** Retry only the chunks that did not complete; completed chunks are reused. */
    public function retryFailed(Generation $generation): bool
    {
        if (! in_array($generation->status, [GenerationStatus::Failed, GenerationStatus::Cancelled], true)) {
            return false;
        }

        DB::transaction(function () use ($generation) {
            $generation->chunks()
                ->whereIn('status', [ChunkStatus::Failed, ChunkStatus::Cancelled, ChunkStatus::Processing])
                ->update(['status' => ChunkStatus::Pending, 'attempts' => 0, 'error_message' => null]);

            $generation->update([
                'status' => GenerationStatus::Pending,
                'stage' => GenerationStage::Queued,
                'error_message' => null,
                'cancel_requested_at' => null,
                'completed_at' => null,
            ]);
        });

        ProcessGeneration::dispatch($generation->id)->afterCommit();

        return true;
    }

    /** Create a brand-new generation with the same script and settings. */
    public function regenerate(Generation $original): Generation
    {
        $copy = DB::transaction(function () use ($original) {
            $copy = $original->replicate([
                'audio_path', 'bundle_path', 'timing_report', 'duration_seconds', 'error_message',
                'started_at', 'completed_at', 'cancel_requested_at', 'completed_chunks',
            ]);
            $copy->fill([
                'regenerated_from_id' => $original->id,
                'status' => GenerationStatus::Pending,
                'stage' => GenerationStage::Queued,
                'progress' => 0,
                'completed_chunks' => 0,
            ]);

            if ($original->mode === GenerationMode::Continuous) {
                $copy->total_chunks = 0; // re-split with current settings
            }
            $copy->save();

            if ($original->mode === GenerationMode::Segments) {
                foreach ($original->chunks as $chunk) {
                    $copy->chunks()->create([
                        'chunk_index' => $chunk->chunk_index,
                        'text' => $chunk->text,
                        'start_ms' => $chunk->start_ms,
                        'end_ms' => $chunk->end_ms,
                        'subtitle_indices' => $chunk->subtitle_indices,
                        'status' => ChunkStatus::Pending,
                    ]);
                }
            }

            return $copy;
        });

        ProcessGeneration::dispatch($copy->id)->afterCommit();

        return $copy;
    }

    /**
     * Pending jobs are cancelled immediately. A running job is asked to stop and
     * does so after the HeyGen request currently in flight finishes.
     */
    public function cancel(Generation $generation): void
    {
        if ($generation->status === GenerationStatus::Pending) {
            $updated = Generation::query()->whereKey($generation->id)
                ->where('status', GenerationStatus::Pending)
                ->update([
                    'status' => GenerationStatus::Cancelled,
                    'cancel_requested_at' => now(),
                    'completed_at' => now(),
                    'error_message' => 'Cancelled by user.',
                ]);
            if ($updated) {
                $generation->chunks()->where('status', ChunkStatus::Pending)->update(['status' => ChunkStatus::Cancelled]);

                return;
            }
        }

        Generation::query()->whereKey($generation->id)->update(['cancel_requested_at' => now()]);
    }

    public function delete(Generation $generation): void
    {
        Storage::disk(config('kikitoons.storage_disk'))->deleteDirectory(self::directory($generation));
        $generation->delete();
    }

    public static function directory(Generation $generation): string
    {
        return 'generations/'.$generation->id;
    }
}
