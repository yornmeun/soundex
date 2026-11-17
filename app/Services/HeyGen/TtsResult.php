<?php

namespace App\Services\HeyGen;

final readonly class TtsResult
{
    public function __construct(
        public string $audioUrl,
        public float $durationSeconds,
        public ?string $requestId,
        /** @var list<array{text:string,start_time:float,end_time:float}> */
        public array $words = [],
    ) {}
}
