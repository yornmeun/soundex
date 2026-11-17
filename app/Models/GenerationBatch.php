<?php

namespace App\Models;

use App\Enums\GenerationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GenerationBatch extends Model
{
    protected $fillable = ['user_id', 'folder_id', 'name', 'settings', 'total_items'];

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class);
    }

    public function generations(): HasMany
    {
        return $this->hasMany(Generation::class, 'batch_id')->orderBy('id');
    }

    /** @return array<string,int> counts per status value */
    public function statusCounts(): array
    {
        $counts = array_fill_keys(array_map(fn ($s) => $s->value, GenerationStatus::cases()), 0);
        foreach ($this->generations as $g) {
            $counts[$g->status->value]++;
        }

        return $counts;
    }
}
