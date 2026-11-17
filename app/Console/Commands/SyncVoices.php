<?php

namespace App\Console\Commands;

use App\Services\HeyGen\HeyGenException;
use App\Services\VoiceService;
use Illuminate\Console\Command;

class SyncVoices extends Command
{
    protected $signature = 'soundex:sync-voices';

    protected $description = 'Load the voices in your HeyGen workspace (never creates voices)';

    public function handle(VoiceService $voices): int
    {
        try {
            $result = $voices->sync();
        } catch (HeyGenException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Voices: {$result['total']} ({$result['active']} active). Default: ".($result['default_voice_name'] ?? 'none'));
        if ($result['default_missing']) {
            $this->warn('The configured default voice is not active in HeyGen.');
        }

        return self::SUCCESS;
    }
}
