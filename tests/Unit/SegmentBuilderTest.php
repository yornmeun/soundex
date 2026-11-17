<?php

namespace Tests\Unit;

use App\Services\Srt\SegmentBuilder;
use App\Services\Srt\SrtCue;
use PHPUnit\Framework\TestCase;

class SegmentBuilderTest extends TestCase
{
    private function cues(): array
    {
        return [
            new SrtCue(1, 1000, 2000, 'Once upon a time,'),
            new SrtCue(2, 2100, 3000, 'there was a cat.'),
            new SrtCue(3, 4000, 5000, 'She loved fish!'),
            new SrtCue(4, 6000, 7000, 'The end'),
        ];
    }

    public function test_subtitle_strategy_creates_one_segment_per_cue_with_original_timing(): void
    {
        $segments = (new SegmentBuilder)->build($this->cues(), SegmentBuilder::STRATEGY_SUBTITLE, 1000);

        $this->assertCount(4, $segments);
        $this->assertSame(['text' => 'She loved fish!', 'start_ms' => 4000, 'end_ms' => 5000, 'subtitle_indices' => [3]], $segments[2]);
    }

    public function test_sentence_strategy_merges_cues_until_the_sentence_ends(): void
    {
        $segments = (new SegmentBuilder)->build($this->cues(), SegmentBuilder::STRATEGY_SENTENCE, 1000);

        $this->assertCount(3, $segments);
        $this->assertSame('Once upon a time, there was a cat.', $segments[0]['text']);
        $this->assertSame(1000, $segments[0]['start_ms']);
        $this->assertSame(3000, $segments[0]['end_ms']);
        $this->assertSame([1, 2], $segments[0]['subtitle_indices']);
        $this->assertSame('The end', $segments[2]['text']);
    }

    public function test_sentence_strategy_respects_max_chars(): void
    {
        $segments = (new SegmentBuilder)->build($this->cues(), SegmentBuilder::STRATEGY_SENTENCE, 20);

        $this->assertSame('Once upon a time,', $segments[0]['text']);
        $this->assertSame('there was a cat.', $segments[1]['text']);
    }
}
