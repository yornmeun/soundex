<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UploadedFile extends Model
{
    protected $fillable = [
        'user_id', 'original_filename', 'stored_path', 'mime_type', 'size_bytes',
        'detected_encoding', 'subtitle_count', 'word_count', 'extracted_script', 'cues',
    ];

    protected function casts(): array
    {
        return ['cues' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function generations(): HasMany
    {
        return $this->hasMany(Generation::class);
    }
}
