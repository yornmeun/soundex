<?php

namespace App\Console\Commands;

use App\Enums\ChunkStatus;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use Illuminate\Console\Command;

class RecoverStaleGenerations extends Command
{
    protected $signature = 'soundex:recover-stale {--minutes=75}';

    protected $description = 'Mark generations stuck in "processing" (e.g. the worker was killed) as failed so they can be retried';

    public function handle(): int
    {
        $stale = Generation::query()
            ->where('status', GenerationStatus::Processing)
            ->where('updated_at', '<', now()->subMinutes((int) $this->option('minutes')))
            ->get();

        foreach ($stale as $generation) {
            $generation->chunks()->where('status', ChunkStatus::Processing)->update(['status' => ChunkStatus::Failed, 'error_message' => 'Interrupted.']);
            $generation->update([
                'status' => GenerationStatus::Failed,
                'completed_at' => now(),
                'error_message' => 'Processing was interrupted (queue worker stopped). Completed chunks were kept; use "Retry failed chunks".',
            ]);
        }

        $this->info("Recovered {$stale->count()} stale generation(s).");

        return self::SUCCESS;
    }
}
