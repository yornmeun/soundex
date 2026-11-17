<?php

namespace App\Listeners;

use App\Events\GenerationFailed;
use App\Events\GenerationFinished;
use App\Notifications\GenerationStatusNotification;

class NotifyGenerationOwner
{
    public function handle(GenerationFinished|GenerationFailed $event): void
    {
        $event->generation->user?->notify(new GenerationStatusNotification(
            $event->generation,
            $event instanceof GenerationFinished,
        ));
    }
}
