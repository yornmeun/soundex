<?php

namespace App\Models;

use App\Enums\ChunkStatus;
use App\Enums\GenerationMode;
use App\Enums\GenerationStage;
use App\Enums\GenerationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Generation extends Model
{
    protected $fillable = [
        'user_id', 'folder_id', 'batch_id', 'uploaded_file_id', 'regenerated_from_id',
        'speaker_voices', 'strip_speaker_labels', 'use_pronunciations', 'pronunciation_dictionary',
        'voice_audio_path', 'mix_settings', 'mix_status', 'mix_error', 'project_name', 'original_filename',
        'script', 'mode', 'voice_id', 'voice_name', 'voice_mode', 'language', 'model', 'output_format',
        'mp3_bitrate', 'speed', 'silence_between_ms', 'normalize_loudness', 'trim_chunk_silence',
        'total_words', 'total_chunks', 'completed_chunks', 'audio_path', 'bundle_path', 'timing_report',
        'duration_seconds', 'status', 'stage', 'progress', 'error_message', 'started_at', 'completed_at',
        'cancel_requested_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => GenerationStatus::class,
            'stage' => GenerationStage::class,
            'mode' => GenerationMode::class,
            'normalize_loudness' => 'boolean',
            'trim_chunk_silence' => 'boolean',
            'timing_report' => 'array',
            'speaker_voices' => 'array',
            'strip_speaker_labels' => 'boolean',
            'use_pronunciations' => 'boolean',
            'pronunciation_dictionary' => 'array',
            'mix_settings' => 'array',
            'duration_seconds' => 'float',
            'speed' => 'float',
            'progress' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancel_requested_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(GenerationBatch::class, 'batch_id');
    }

    public function uploadedFile(): BelongsTo
    {
        return $this->belongsTo(UploadedFile::class);
    }

    public function regeneratedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'regenerated_from_id');
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(GenerationChunk::class)->orderBy('chunk_index');
    }

    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('project_name', 'like', "%{$term}%")
                ->orWhere('original_filename', 'like', "%{$term}%")
                ->orWhere('voice_name', 'like', "%{$term}%");
        });
    }

    public function hasAudio(): bool
    {
        return $this->status === GenerationStatus::Completed && filled($this->audio_path);
    }

    /** Narration without music/SFX (older generations only have audio_path). */
    public function voiceAudioPath(): ?string
    {
        return $this->voice_audio_path ?: $this->audio_path;
    }

    public function hasMix(): bool
    {
        $mix = $this->mix_settings ?? [];

        return ! empty($mix['music']['asset_id']) || ! empty($mix['sfx']);
    }

    public function isMixing(): bool
    {
        return in_array($this->mix_status, ['pending', 'processing'], true);
    }

    /** Narration-timed SRT needs HeyGen word timestamps for every chunk. */
    public function hasWordTimings(): bool
    {
        if (! $this->hasAudio() || $this->trim_chunk_silence) {
            return false;
        }

        return ! $this->chunks()->whereNull('words')->exists();
    }

    public function hasFailedChunks(): bool
    {
        return $this->chunks()->whereIn('status', [ChunkStatus::Failed, ChunkStatus::Cancelled, ChunkStatus::Pending])->exists();
    }

    public function isCancellationRequested(): bool
    {
        // Always read from the database: cancellation is requested from a web request
        // while the queue worker holds its own in-memory copy of the model.
        return self::query()->whereKey($this->id)->whereNotNull('cancel_requested_at')->exists();
    }

    public function processingSeconds(): ?int
    {
        if (! $this->started_at) {
            return null;
        }

        return (int) $this->started_at->diffInSeconds($this->completed_at ?? now());
    }

    public function audioMimeType(): string
    {
        return $this->output_format === 'wav' ? 'audio/wav' : 'audio/mpeg';
    }

    public function downloadFilename(?string $extension = null): string
    {
        $base = str($this->project_name)->slug()->whenEmpty(fn () => str('narration'))->toString();

        return $base.'-'.$this->id.'.'.($extension ?? $this->output_format);
    }
}
