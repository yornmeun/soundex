<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Folder extends Model
{
    public const COLORS = ['violet', 'sky', 'emerald', 'amber', 'rose', 'slate'];

    protected $fillable = ['user_id', 'name', 'description', 'color'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function generations(): HasMany
    {
        return $this->hasMany(Generation::class);
    }

    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }

    public function dotClass(): string
    {
        return match ($this->color) {
            'sky' => 'bg-sky-500', 'emerald' => 'bg-emerald-500', 'amber' => 'bg-amber-500',
            'rose' => 'bg-rose-500', 'slate' => 'bg-slate-500', default => 'bg-brand-500',
        };
    }
}
