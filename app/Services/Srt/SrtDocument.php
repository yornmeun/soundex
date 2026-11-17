<?php

namespace App\Services\Srt;

final readonly class SrtDocument
{
    /** @param list<SrtCue> $cues */
    public function __construct(
        public array $cues,
        public string $encoding,
    ) {}

    public function count(): int
    {
        return count($this->cues);
    }

    public function first(): SrtCue
    {
        return $this->cues[0];
    }

    public function last(): SrtCue
    {
        return $this->cues[array_key_last($this->cues)];
    }

    public function toArray(): array
    {
        return array_map(fn (SrtCue $cue) => $cue->toArray(), $this->cues);
    }
}
