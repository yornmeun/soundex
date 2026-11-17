<?php

namespace App\Console\Commands;

use App\Models\UploadedFile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneUploads extends Command
{
    protected $signature = 'kikitoons:prune-uploads';

    protected $description = 'Delete uploaded SRT files that were never used for a generation';

    public function handle(): int
    {
        $count = 0;
        UploadedFile::query()
            ->whereDoesntHave('generations')
            ->where('created_at', '<', now()->subDays((int) config('kikitoons.prune_uploads_after_days')))
            ->each(function (UploadedFile $file) use (&$count) {
                Storage::disk(config('kikitoons.storage_disk'))->delete($file->stored_path);
                $file->delete();
                $count++;
            });

        $this->info("Pruned {$count} unused upload(s).");

        return self::SUCCESS;
    }
}
