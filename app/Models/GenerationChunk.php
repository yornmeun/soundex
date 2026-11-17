<?php

namespace App\Models;

use App\Enums\ChunkStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GenerationChunk extends Model
{
    protected $fillable = [
        'generation_id', 'chunk_index', 'text', 'start_ms', 'end_ms', 'subtitle_indices',
        'speaker', 'voice_id', 'voice_name', 'voice_mode', 'words',
        'audio_path', 'duration_seconds', 'heygen_request_id', 'status', 'attempts', 'error_message',
    ];

    protected function casts(): array
    {
        return [
            'status' => ChunkStatus::class,
            'subtitle_indices' => 'array',
            'words' => 'array',
            'duration_seconds' => 'float',
            'start_ms' => 'integer',
            'end_ms' => 'integer',
            'attempts' => 'integer',
        ];
    }

    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class);
    }

    /** Seconds available between the subtitle start and end (segments mode). */
    public function availableSeconds(): ?float
    {
        if ($this->start_ms === null || $this->end_ms === null) {
            return null;
        }

        return max(0, ($this->end_ms - $this->start_ms) / 1000);
    }
}
