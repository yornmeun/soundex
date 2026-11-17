<?php

namespace Tests\Unit;

use App\Exceptions\InvalidSrtException;
use App\Services\Srt\SrtParser;
use PHPUnit\Framework\TestCase;

class SrtParserTest extends TestCase
{
    private SrtParser $parser;

    protected function setUp(): void
    {
        $this->parser = new SrtParser;
    }

    private function fixture(string $name): string
    {
        return file_get_contents(__DIR__.'/../Fixtures/'.$name);
    }

    public function test_it_parses_standard_srt_cues_with_timestamps(): void
    {
        $doc = $this->parser->parse($this->fixture('sample.srt'));

        $this->assertSame(3, $doc->count());
        $this->assertSame(1000, $doc->cues[0]->startMs);
        $this->assertSame(3500, $doc->cues[0]->endMs);
        $this->assertSame(12250, $doc->cues[2]->endMs);
        $this->assertSame('Once upon a time, in a small village,', $doc->cues[0]->text);
    }

    public function test_it_removes_formatting_tags_but_keeps_text(): void
    {
        $doc = $this->parser->parse($this->fixture('sample.srt'));

        $this->assertSame('there lived a curious little cat named Kiki.', $doc->cues[1]->text);
        $this->assertStringNotContainsString('{\an8}', $doc->cues[2]->text);
    }

    public function test_multiline_subtitles_are_joined_with_a_space_preserving_punctuation(): void
    {
        $doc = $this->parser->parse($this->fixture('sample.srt'));

        $this->assertSame('Every morning, Kiki asked: "What will we discover today?"', $doc->cues[2]->text);
    }

    public function test_sequence_numbers_and_timestamps_are_not_in_the_script(): void
    {
        $doc = $this->parser->parse($this->fixture('sample.srt'));
        $script = $this->parser->buildScript($doc->cues);

        $this->assertStringNotContainsString('-->', $script);
        $this->assertDoesNotMatchRegularExpression('/^\d+$/m', $script);
        // 3s pause between cue 2 and 3 becomes a paragraph break; the rest is space-joined.
        $this->assertSame(
            "Once upon a time, in a small village, there lived a curious little cat named Kiki.\n\nEvery morning, Kiki asked: \"What will we discover today?\"",
            $script,
        );
    }

    public function test_khmer_unicode_and_crlf_line_endings_are_preserved(): void
    {
        $doc = $this->parser->parse($this->fixture('khmer.srt'));

        $this->assertSame(2, $doc->count());
        $this->assertSame('សួស្តី! ខ្ញុំឈ្មោះគីគី។', $doc->cues[0]->text);
        $this->assertSame('ថ្ងៃនេះយើងនឹងរៀនអំពីព្រៃឈើ។', $doc->cues[1]->text);
        $this->assertSame('UTF-8', $doc->encoding);
    }

    public function test_utf8_bom_and_utf16_files_are_decoded(): void
    {
        $content = $this->fixture('khmer.srt');

        $bom = $this->parser->parse("\xEF\xBB\xBF".$content);
        $this->assertSame('សួស្តី! ខ្ញុំឈ្មោះគីគី។', $bom->cues[0]->text);

        $utf16 = $this->parser->parse("\xFF\xFE".mb_convert_encoding($content, 'UTF-16LE', 'UTF-8'));
        $this->assertSame('ថ្ងៃនេះយើងនឹងរៀនអំពីព្រៃឈើ។', $utf16->cues[1]->text);
        $this->assertSame('UTF-16LE', $utf16->encoding);
    }

    public function test_empty_file_is_rejected(): void
    {
        $this->expectException(InvalidSrtException::class);
        $this->expectExceptionMessage('empty');

        $this->parser->parse("  \n\r\n ");
    }

    public function test_file_without_timestamps_is_rejected(): void
    {
        $this->expectException(InvalidSrtException::class);
        $this->expectExceptionMessage('No valid subtitles');

        $this->parser->parse("Just some text\nwithout any subtitle timing.\n");
    }

    public function test_file_with_timestamps_but_no_text_is_rejected(): void
    {
        $this->expectException(InvalidSrtException::class);

        $this->parser->parse("1\n00:00:01,000 --> 00:00:02,000\n\n2\n00:00:03,000 --> 00:00:04,000\n<i></i>\n");
    }

    public function test_dot_millisecond_separator_and_missing_trailing_newline(): void
    {
        $doc = $this->parser->parse("1\n00:01:02.5 --> 00:01:03.250\nHello world");

        $this->assertSame(62500, $doc->cues[0]->startMs);
        $this->assertSame(63250, $doc->cues[0]->endMs);
        $this->assertSame('Hello world', $doc->cues[0]->text);
    }

    public function test_numbers_inside_subtitle_text_are_kept(): void
    {
        $doc = $this->parser->parse("1\n00:00:01,000 --> 00:00:02,000\nI have 3 cats\nand 2 dogs.\n\n2\n00:00:02,000 --> 00:00:03,000\nThe end.\n");

        $this->assertSame('I have 3 cats and 2 dogs.', $doc->cues[0]->text);
        $this->assertSame('The end.', $doc->cues[1]->text);
    }
}
