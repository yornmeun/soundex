<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AudioAsset extends Model
{
    public const TYPE_MUSIC = 'music';

    public const TYPE_SFX = 'sfx';

    protected $fillable = ['user_id', 'type', 'name', 'original_filename', 'path', 'mime_type', 'size_bytes', 'duration_seconds'];

    protected function casts(): array
    {
        return ['duration_seconds' => 'float'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }
}
