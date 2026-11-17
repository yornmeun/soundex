<?php

namespace App\Events;

use App\Models\Generation;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class GenerationFailed
{
    use Dispatchable, SerializesModels;

    public function __construct(public Generation $generation) {}
}
