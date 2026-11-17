<?php

namespace Tests\Unit;

use App\Services\Text\TextChunker;
use App\Services\Text\TextStats;
use PHPUnit\Framework\TestCase;

class TextChunkerTest extends TestCase
{
    private TextChunker $chunker;

    protected function setUp(): void
    {
        $this->chunker = new TextChunker;
    }

    private function normalize(string $s): string
    {
        return preg_replace('/\s+/u', ' ', trim($s));
    }

    public function test_short_text_is_a_single_chunk(): void
    {
        $this->assertSame(['Hello there. How are you?'], $this->chunker->chunk('Hello there. How are you?', 100));
    }

    public function test_empty_text_gives_no_chunks(): void
    {
        $this->assertSame([], $this->chunker->chunk("  \n ", 100));
    }

    public function test_chunks_split_on_sentence_boundaries_and_respect_the_limit(): void
    {
        $sentences = [];
        for ($i = 1; $i <= 40; $i++) {
            $sentences[] = "This is sentence number {$i}, and it tells part of the story.";
        }
        $text = implode(' ', $sentences);

        $chunks = $this->chunker->chunk($text, 300);

        $this->assertGreaterThan(1, count($chunks));
        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(300, mb_strlen($chunk));
            $this->assertMatchesRegularExpression('/\.$/', $chunk, 'Every chunk should end at a sentence boundary.');
        }
    }

    public function test_chunk_order_is_preserved_and_text_is_unchanged(): void
    {
        $text = "First paragraph. It has two sentences!\n\nSecond paragraph? Yes, indeed; it does: really. \"Quoted end.\" Final words…";

        $chunks = $this->chunker->chunk($text, 40);

        $this->assertSame($this->normalize($text), $this->normalize(implode(' ', $chunks)));
        $this->assertStringStartsWith('First paragraph.', $chunks[0]);
        $this->assertStringEndsWith('Final words…', end($chunks));
    }

    public function test_a_long_sentence_is_split_at_clauses_then_words_without_cutting_words(): void
    {
        $sentence = str_repeat('wonderful ', 60).'adventure, '.str_repeat('magical ', 40).'end.';

        $chunks = $this->chunker->chunk($sentence, 120);

        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(120, mb_strlen($chunk));
            foreach (explode(' ', $chunk) as $word) {
                $this->assertContains(rtrim($word, ',.'), ['wonderful', 'adventure', 'magical', 'end']);
            }
        }
        $this->assertSame($this->normalize($sentence), $this->normalize(implode(' ', $chunks)));
    }

    public function test_khmer_sentences_split_on_khan_and_never_break_grapheme_clusters(): void
    {
        $sentence = 'ថ្ងៃនេះយើងនឹងរៀនអំពីព្រៃឈើដ៏ស្រស់ស្អាត។';
        $text = str_repeat($sentence, 12);

        $chunks = $this->chunker->chunk($text, 100);

        $this->assertGreaterThan(1, count($chunks));
        $this->assertSame($text, implode('', $chunks));
        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(100, mb_strlen($chunk));
            $this->assertStringEndsWith('។', $chunk);
        }
    }

    public function test_khmer_without_any_separator_falls_back_to_grapheme_clusters(): void
    {
        $text = str_repeat('ខ្ញុំស្រឡាញ់', 30); // no spaces, no punctuation

        $chunks = $this->chunker->chunk($text, 50);

        $this->assertSame($text, implode('', $chunks));
        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(50, mb_strlen($chunk));
            // A chunk must never start with a combining mark / coeng (U+17B6–U+17D3).
            $this->assertDoesNotMatchRegularExpression('/^[\x{17B6}-\x{17D3}]/u', $chunk);
        }
    }

    public function test_text_stats_estimates(): void
    {
        $stats = new TextStats;

        $this->assertSame(5, $stats->wordCount('One two  three four five'));
        $this->assertSame(60, $stats->estimateSeconds(str_repeat('word ', 150), 150));
        $this->assertTrue($stats->containsKhmer('Hello គីគី'));
    }
}
