<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Services\Audio\NarrationMixer;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Re-applies music / sound effects to an existing narration. No HeyGen calls. */
class MixGeneration implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public int $uniqueFor = 1900;

    public function __construct(public readonly int $generationId) {}

    public function uniqueId(): string
    {
        return 'mix-'.$this->generationId;
    }

    public function handle(NarrationMixer $mixer): void
    {
        $generation = Generation::query()->find($this->generationId);
        if (! $generation || ! $generation->hasAudio()) {
            return;
        }

        $generation->update(['mix_status' => 'processing', 'mix_error' => null]);

        try {
            $mixer->apply($generation);
            $generation->update(['mix_status' => null]);
        } catch (Throwable $e) {
            $generation->update(['mix_status' => 'failed', 'mix_error' => mb_substr($e->getMessage(), 0, 1000)]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        Generation::query()->whereKey($this->generationId)->update([
            'mix_status' => 'failed',
            'mix_error' => 'The mixing job stopped unexpectedly'.($exception ? ': '.mb_substr($exception->getMessage(), 0, 500) : '.'),
        ]);
    }
}
