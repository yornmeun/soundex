<?php

namespace Tests\Support;

use App\Services\Audio\AudioProcessor;

/** Records merge calls and writes placeholder files instead of running FFmpeg. */
class FakeAudioProcessor extends AudioProcessor
{
    public array $merged = [];

    public array $timelines = [];

    public function __construct(public bool $available = true) {}

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function probeDuration(string $path): float
    {
        return 1.5;
    }

    public function merge(array $inputs, string $output, array $options = []): void
    {
        $this->merged[] = compact('inputs', 'output', 'options');
        @mkdir(dirname($output), 0775, true);
        file_put_contents($output, 'MERGED:'.implode('|', array_map('basename', $inputs)));
    }

    public function timeline(array $clips, string $output, array $options = []): void
    {
        $this->timelines[] = compact('clips', 'output', 'options');
        @mkdir(dirname($output), 0775, true);
        file_put_contents($output, 'TIMELINE');
    }
}
