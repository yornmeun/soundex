<?php

namespace Tests\Unit;

use App\Services\Text\SpeakerParser;
use PHPUnit\Framework\TestCase;

class SpeakerParserTest extends TestCase
{
    public function test_detects_colon_and_bracket_labels(): void
    {
        $p = new SpeakerParser;

        $this->assertSame('kiki', $p->detect('Kiki: Where are we going?')['key']);
        $this->assertSame('Old Man', $p->detect('Old Man: Follow me.')['label']);
        $this->assertSame('narrator', $p->detect('[Narrator] Once upon a time.')['key']);
        $this->assertSame('គីគី', $p->detect('គីគី: សួស្តី!')['label']);
    }

    public function test_ordinary_sentences_are_not_labels(): void
    {
        $p = new SpeakerParser;

        $this->assertNull($p->detect('Every morning, Kiki asked: "What will we discover today?"'));
        $this->assertNull($p->detect('She said the time was 10:30 already.'));
        $this->assertNull($p->detect('Kiki:'), 'A label with no text after it is not a speaker line.');
    }

    public function test_speakers_are_listed_in_order_with_line_counts(): void
    {
        $script = "Once upon a time.\nKiki: Hello!\nMomo: Hi Kiki.\nkiki: Let's go.";

        $this->assertSame([
            ['key' => 'kiki', 'label' => 'Kiki', 'lines' => 2],
            ['key' => 'momo', 'label' => 'Momo', 'lines' => 1],
        ], (new SpeakerParser)->speakers($script));
    }

    public function test_turns_use_only_mapped_speakers_and_keep_unmapped_text_unchanged(): void
    {
        $script = "Once upon a time.\nKiki: Hello!\nNote: this is not a person.\nKiki: Bye.";

        $turns = (new SpeakerParser)->turns($script, ['kiki' => ['voice_id' => 'v2']], stripLabels: true);

        $this->assertSame([
            ['speaker' => null, 'text' => 'Once upon a time.'],
            ['speaker' => 'kiki', 'text' => 'Hello!'],
            ['speaker' => null, 'text' => 'Note: this is not a person.'],
            ['speaker' => 'kiki', 'text' => 'Bye.'],
        ], $turns);
    }

    public function test_labels_can_be_kept_and_consecutive_lines_merge(): void
    {
        $turns = (new SpeakerParser)->turns("Kiki: One.\nKiki: Two.\nThe end.", ['kiki' => ['voice_id' => 'v2']], stripLabels: false);

        $this->assertSame([
            ['speaker' => 'kiki', 'text' => "Kiki: One.\nKiki: Two."],
            ['speaker' => null, 'text' => 'The end.'],
        ], $turns);
    }
}
