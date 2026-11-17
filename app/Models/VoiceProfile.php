<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class VoiceProfile extends Model
{
    protected $fillable = [
        'voice_id', 'name', 'mode', 'language', 'gender', 'status', 'failure_reason',
        'is_default', 'is_available', 'heygen_created_at', 'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_available' => 'boolean',
            'heygen_created_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public function scopeUsable(Builder $query): Builder
    {
        return $query->where('status', 'ACTIVE')->where('is_available', true);
    }

    public function isActive(): bool
    {
        return $this->status === 'ACTIVE' && $this->is_available;
    }

    public function isProfessional(): bool
    {
        return $this->mode === 'professional';
    }
}
