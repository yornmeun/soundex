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
use App\Services\PronunciationService;
use App\Services\SettingsService;
use App\Services\Srt\SegmentBuilder;
use App\Services\Srt\SrtCue;
use App\Services\Text\SpeakerParser;
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
        private readonly SpeakerParser $speakers,
        private readonly PronunciationService $pronunciations,
    ) {}

    /**
     * Resolve the character -> voice mapping from the request. Only ACTIVE voices are accepted;
     * speakers mapped to null are read as plain narrator text (label kept).
     *
     * @param  list<array{key:string,label?:string,voice_profile_id?:int|null}>|null  $speakers
     * @return array<string,array{label:string,voice_id:string,voice_name:string,voice_mode:?string}>
     */
    public function resolveSpeakerVoices(?array $speakers): array
    {
        $map = [];
        foreach ((array) $speakers as $row) {
            if (empty($row['voice_profile_id'])) {
                continue;
            }
            $voice = VoiceProfile::query()->find($row['voice_profile_id']);
            if (! $voice || ! $voice->isActive()) {
                throw ValidationException::withMessages(['speakers' => 'The voice chosen for “'.($row['label'] ?? $row['key']).'” is not active in HeyGen.']);
            }
            $map[SpeakerParser::key((string) $row['key'])] = [
                'label' => (string) ($row['label'] ?? $row['key']),
                'voice_id' => $voice->voice_id,
                'voice_name' => $voice->name,
                'voice_mode' => $voice->mode,
            ];
        }

        return $map;
    }

    /** Music chosen in the wizard / batch form, stored as the generation's mix settings. */
    public function musicSettings(array $data): ?array
    {
        if (empty($data['music_asset_id'])) {
            return null;
        }

        return ['music' => [
            'asset_id' => (int) $data['music_asset_id'],
            'volume_db' => (float) ($data['music_volume_db'] ?? -18),
            'duck' => (bool) ($data['music_duck'] ?? true),
            'fade_in' => 2,
            'fade_out' => 3,
        ], 'sfx' => []];
    }

    /**
     * Create a generation from validated wizard input and queue it.
     *
     * @param  array<string,mixed>  $data  validated StoreGenerationRequest data
     */
    public function create(User $user, array $data, VoiceProfile $voice, ?UploadedFile $upload): Generation
    {
        $mode = GenerationMode::from($data['mode']);
        $speakerVoices = $this->resolveSpeakerVoices($data['speakers'] ?? null);
        $strip = (bool) ($data['strip_speaker_labels'] ?? true);
        $usePronunciations = (bool) ($data['use_pronunciations'] ?? true);

        $generation = DB::transaction(function () use ($user, $data, $voice, $upload, $mode, $speakerVoices, $strip, $usePronunciations) {
            $script = (string) $data['script'];
            $segmentRows = [];

            if ($mode === GenerationMode::Segments) {
                $segmentRows = $this->applySpeakersToSegments($this->buildSegments($upload, $data), $speakerVoices, $strip);
                $script = implode("\n", array_column($segmentRows, 'original_text'));
            }

            $generation = Generation::query()->create([
                'user_id' => $user->id,
                'folder_id' => $data['folder_id'] ?? null,
                'batch_id' => $data['batch_id'] ?? null,
                'speaker_voices' => $speakerVoices ?: null,
                'strip_speaker_labels' => $strip,
                'use_pronunciations' => $usePronunciations,
                'pronunciation_dictionary' => $usePronunciations ? ($this->pronunciations->dictionaryFor($user) ?: null) : null,
                'mix_settings' => $this->musicSettings($data),
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
                    'speaker' => $row['voice']['label'] ?? null,
                    'voice_id' => $row['voice']['voice_id'] ?? null,
                    'voice_name' => $row['voice']['voice_name'] ?? null,
                    'voice_mode' => $row['voice']['voice_mode'] ?? null,
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

    /** Give segments that start with a mapped speaker label that speaker's voice. */
    private function applySpeakersToSegments(array $rows, array $speakerVoices, bool $strip): array
    {
        foreach ($rows as &$row) {
            $row['original_text'] = $row['text'];
            $row['voice'] = null;
            $hit = $this->speakers->detect($row['text']);
            if ($hit && isset($speakerVoices[$hit['key']])) {
                $row['voice'] = $speakerVoices[$hit['key']];
                if ($strip) {
                    $row['text'] = trim($hit['rest']);
                }
            }
        }
        unset($row);

        return $rows;
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
            min((int) $this->settings->get('generation.chunk_max_chars'), (int) config('soundex.heygen.max_chars_per_request')),
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
                'audio_path', 'voice_audio_path', 'bundle_path', 'timing_report', 'duration_seconds', 'error_message',
                'started_at', 'completed_at', 'cancel_requested_at', 'completed_chunks', 'mix_status', 'mix_error',
            ]);
            $copy->fill([
                'regenerated_from_id' => $original->id,
                // Use the CURRENT dictionary: regenerating is how fixed pronunciations get applied.
                'pronunciation_dictionary' => $original->use_pronunciations && $original->user
                    ? ($this->pronunciations->dictionaryFor($original->user) ?: null)
                    : null,
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
                        'speaker' => $chunk->speaker,
                        'voice_id' => $chunk->voice_id,
                        'voice_name' => $chunk->voice_name,
                        'voice_mode' => $chunk->voice_mode,
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
        Storage::disk(config('soundex.storage_disk'))->deleteDirectory(self::directory($generation));
        $generation->delete();
    }

    public static function directory(Generation $generation): string
    {
        return 'generations/'.$generation->id;
    }
}
