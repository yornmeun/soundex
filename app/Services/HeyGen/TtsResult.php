<?php

namespace App\Services\HeyGen;

final readonly class TtsResult
{
    public function __construct(
        public string $audioUrl,
        public float $durationSeconds,
        public ?string $requestId,
    ) {}
}
