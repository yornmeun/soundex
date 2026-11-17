<?php

namespace App\Jobs;

use App\Enums\GenerationStatus;
use App\Events\GenerationFailed;
use App\Models\Generation;
use App\Services\Generation\GenerationProcessor;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Processes one generation. ShouldBeUnique prevents the same generation from
 * being queued twice; GenerationProcessor additionally claims the row atomically.
 * Retries of individual HeyGen requests happen inside the processor, so the job
 * itself is attempted only once (no blind re-run that could double-bill).
 */
class ProcessGeneration implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 3900;

    public function __construct(public readonly int $generationId) {}

    public function uniqueId(): string
    {
        return (string) $this->generationId;
    }

    public function handle(GenerationProcessor $processor): void
    {
        $processor->process($this->generationId);
    }

    public function failed(?Throwable $exception): void
    {
        $generation = Generation::query()->find($this->generationId);
        if (! $generation || $generation->status->isFinished()) {
            return;
        }

        $generation->update([
            'status' => GenerationStatus::Failed,
            'completed_at' => now(),
            'error_message' => 'The background job stopped unexpectedly'.($exception ? ': '.mb_substr($exception->getMessage(), 0, 500) : '.')
                .' Completed chunks were kept; use "Retry failed chunks".',
        ]);

        GenerationFailed::dispatch($generation);
    }
}
