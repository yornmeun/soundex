<?php

namespace Tests\Unit;

use App\Services\Srt\SrtCue;
use App\Services\Srt\SrtWriter;
use App\Services\Srt\SubtitleAligner;
use PHPUnit\Framework\TestCase;

class SubtitleAlignerTest extends TestCase
{
    private function cues(): array
    {
        return [
            new SrtCue(1, 1000, 2000, 'Once upon a time,'),
            new SrtCue(2, 2000, 4000, 'there lived a curous cat.'),
            new SrtCue(3, 5000, 6000, 'The end.'),
        ];
    }

    private function texts(array $cues): array
    {
        return array_column($cues, 'text');
    }

    public function test_unchanged_script_keeps_every_cue(): void
    {
        $out = (new SubtitleAligner)->align($this->cues(), "Once upon a time, there lived a curous cat.\n\nThe end.");

        $this->assertSame(['Once upon a time,', 'there lived a curous cat.', 'The end.'], $this->texts($out));
        $this->assertSame([1000, 2000, 5000], array_column($out, 'start_ms'));
    }

    public function test_a_corrected_word_stays_in_its_cue(): void
    {
        $out = (new SubtitleAligner)->align($this->cues(), 'Once upon a time, there lived a curious cat. The end.');

        $this->assertSame('there lived a curious cat.', $out[1]['text']);
        $this->assertSame(2000, $out[1]['start_ms']);
    }

    public function test_inserted_words_join_the_previous_cue_and_deleted_cues_disappear(): void
    {
        $out = (new SubtitleAligner)->align($this->cues(), 'Once upon a long long time, there lived a curous cat.');

        $this->assertSame(['Once upon a long long time,', 'there lived a curous cat.'], $this->texts($out));
    }

    public function test_khmer_cue_without_spaces_is_replaced_in_place(): void
    {
        $cues = [new SrtCue(1, 0, 1000, 'សួស្តី!'), new SrtCue(2, 1000, 2000, 'ខ្ញុំឈ្មោះគីគី។'), new SrtCue(3, 2000, 3000, 'លាហើយ។')];

        $out = (new SubtitleAligner)->align($cues, 'សួស្តី! ខ្ញុំឈ្មោះគីគីណា។ លាហើយ។');

        $this->assertSame(['សួស្តី!', 'ខ្ញុំឈ្មោះគីគីណា។', 'លាហើយ។'], $this->texts($out));
    }

    public function test_a_complete_rewrite_still_produces_timed_cues(): void
    {
        $out = (new SubtitleAligner)->align($this->cues(), 'Completely different words here now.');

        $this->assertNotEmpty($out);
        $this->assertSame('Completely different words here now.', implode(' ', $this->texts($out)));
    }

    public function test_srt_writer_formats_cues(): void
    {
        $srt = (new SrtWriter)->write([['start_ms' => 1500, 'end_ms' => 3723456, 'text' => 'Hello'], ['start_ms' => 0, 'end_ms' => 1, 'text' => '  ']]);

        $this->assertSame("1\n00:00:01,500 --> 01:02:03,456\nHello\n", $srt);
    }
}
