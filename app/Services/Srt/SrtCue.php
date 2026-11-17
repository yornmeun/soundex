<?php

namespace App\Services\Srt;

final readonly class SrtCue
{
    public function __construct(
        public int $index,
        public int $startMs,
        public int $endMs,
        public string $text,
    ) {}

    public function toArray(): array
    {
        return [
            'index' => $this->index,
            'start_ms' => $this->startMs,
            'end_ms' => $this->endMs,
            'text' => $this->text,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self((int) $data['index'], (int) $data['start_ms'], (int) $data['end_ms'], (string) $data['text']);
    }
}
