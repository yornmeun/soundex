<?php

namespace App\Services\Srt;

use App\Exceptions\InvalidSrtException;
use App\Services\Text\SpeakerParser;

/**
 * Parses SubRip (.srt) subtitle files.
 *
 * - Accepts UTF-8 (with/without BOM), UTF-16 LE/BE (with BOM) and falls back to
 *   Windows-1252 for legacy files. Khmer and other Unicode scripts are preserved.
 * - Removes sequence numbers, timestamps and formatting tags (<i>, <font>, {\an8}).
 * - Joins multiline subtitle text with a single space. Words and punctuation are
 *   never altered.
 */
class SrtParser
{
    private const TIMESTAMP = '/^\s*(\d{1,2}):(\d{1,2}):(\d{1,2})[,.](\d{1,3})\s*-->\s*(\d{1,2}):(\d{1,2}):(\d{1,2})[,.](\d{1,3})/';

    public function parse(string $raw): SrtDocument
    {
        [$content, $encoding] = $this->decode($raw);

        if (trim($content) === '') {
            throw InvalidSrtException::empty();
        }

        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $content));

        /** @var list<array{index:int,start:int,end:int,lines:list<string>}> $blocks */
        $blocks = [];
        $current = null;

        foreach ($lines as $line) {
            if (preg_match(self::TIMESTAMP, $line, $m)) {
                if ($current !== null) {
                    // The sequence number of the next cue sits at the end of the previous block.
                    $current['lines'] = $this->stripTrailingIndex($current['lines']);
                    $blocks[] = $current;
                }

                $current = [
                    'index' => count($blocks) + 1,
                    'start' => $this->toMs($m[1], $m[2], $m[3], $m[4]),
                    'end' => $this->toMs($m[5], $m[6], $m[7], $m[8]),
                    'lines' => [],
                ];

                continue;
            }

            if ($current !== null) {
                $current['lines'][] = $line;
            }
        }

        if ($current !== null) {
            $blocks[] = $current;
        }

        if ($blocks === []) {
            throw InvalidSrtException::noCues();
        }

        $cues = [];
        foreach ($blocks as $block) {
            $text = $this->cleanText($block['lines']);
            if ($text === '') {
                continue;
            }
            $cues[] = new SrtCue(count($cues) + 1, $block['start'], max($block['start'], $block['end']), $text);
        }

        if ($cues === []) {
            throw InvalidSrtException::noText();
        }

        return new SrtDocument($cues, $encoding);
    }

    /**
     * Build the narration script: cues are joined with a single space, and a
     * paragraph break is inserted where the subtitles pause for a long time.
     * Only whitespace is added; the text itself is untouched.
     *
     * @param  list<SrtCue>  $cues
     */
    public function buildScript(array $cues, int $paragraphGapMs = 2000): string
    {
        $script = '';
        $previous = null;

        $speakers = new SpeakerParser;

        foreach ($cues as $cue) {
            if ($previous !== null) {
                $script .= match (true) {
                    ($cue->startMs - $previous->endMs) >= $paragraphGapMs => "\n\n",
                    // Character lines ("Kiki: …") start on their own line so each can get its own voice.
                    $speakers->detect($cue->text) !== null => "\n",
                    default => ' ',
                };
            }
            $script .= $cue->text;
            $previous = $cue;
        }

        return $script;
    }

    /** @return array{0:string,1:string} [utf8 content, detected encoding] */
    public function decode(string $raw): array
    {
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            return [substr($raw, 3), 'UTF-8 (BOM)'];
        }
        if (str_starts_with($raw, "\xFF\xFE")) {
            return [mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16LE'), 'UTF-16LE'];
        }
        if (str_starts_with($raw, "\xFE\xFF")) {
            return [mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16BE'), 'UTF-16BE'];
        }
        if (mb_check_encoding($raw, 'UTF-8')) {
            return [$raw, 'UTF-8'];
        }

        $converted = @mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        if ($converted === false || ! mb_check_encoding($converted, 'UTF-8')) {
            throw InvalidSrtException::unreadableEncoding();
        }

        return [$converted, 'Windows-1252'];
    }

    /** @param list<string> $lines */
    private function stripTrailingIndex(array $lines): array
    {
        while ($lines !== [] && trim(end($lines)) === '') {
            array_pop($lines);
        }
        if ($lines !== [] && preg_match('/^\s*\d+\s*$/', end($lines))) {
            array_pop($lines);
        }

        return $lines;
    }

    /** @param list<string> $lines */
    private function cleanText(array $lines): string
    {
        $parts = [];
        foreach ($lines as $line) {
            // HTML-like tags: <i>, </b>, <font color="...">, <u> ...
            $line = preg_replace('/<\/?[a-zA-Z][^>]*>/u', '', $line) ?? $line;
            // SSA/ASS override tags such as {\an8} or {\i1}
            $line = preg_replace('/\{\\\\[^}]*\}/u', '', $line) ?? $line;
            $line = trim(preg_replace('/[ \t\x{00A0}]+/u', ' ', $line) ?? $line);
            if ($line !== '') {
                $parts[] = $line;
            }
        }

        return implode(' ', $parts);
    }

    private function toMs(string $h, string $m, string $s, string $ms): int
    {
        return ((int) $h * 3600 + (int) $m * 60 + (int) $s) * 1000 + (int) str_pad($ms, 3, '0');
    }
}
