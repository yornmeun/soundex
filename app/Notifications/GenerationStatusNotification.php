<?php

namespace App\Notifications;

use App\Models\Generation;
use Illuminate\Notifications\Notification;

class GenerationStatusNotification extends Notification
{
    public function __construct(
        public readonly Generation $generation,
        public readonly bool $succeeded,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'generation_id' => $this->generation->id,
            'project_name' => $this->generation->project_name,
            'succeeded' => $this->succeeded,
            'message' => $this->succeeded
                ? "“{$this->generation->project_name}” is ready to play and download."
                : "“{$this->generation->project_name}” failed: ".mb_strimwidth((string) $this->generation->error_message, 0, 140, '…'),
        ];
    }
}
