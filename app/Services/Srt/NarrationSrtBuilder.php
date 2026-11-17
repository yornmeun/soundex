<?php

namespace App\Services\Srt;

use App\Enums\GenerationMode;
use App\Models\Generation;
use App\Models\GenerationChunk;

/**
 * Builds subtitles timed to the generated narration from HeyGen's measured word
 * timestamps (with_timestamps=true). Offsets follow the merge: chunk durations
 * plus the configured pause (continuous) or original start times (segments).
 */
class NarrationSrtBuilder
{
    public function __construct(
        private readonly int $maxChars = 84,
        private readonly float $maxSeconds = 6.0,
    ) {}

    /** @return list<array{start_ms:int,end_ms:int,text:string}> */
    public function build(Generation $generation): array
    {
        $cues = [];
        $offset = 0.0;
        $pause = $generation->silence_between_ms / 1000;
        $segments = $generation->mode === GenerationMode::Segments;

        foreach ($generation->chunks()->get() as $i => $chunk) {
            $base = $segments ? ((int) $chunk->start_ms) / 1000 : $offset + ($i > 0 ? $pause : 0);
            array_push($cues, ...$this->chunkCues($chunk, $base));
            if (! $segments) {
                $offset = $base + (float) $chunk->duration_seconds;
            }
        }

        return $cues;
    }

    /** @return list<array{start_ms:int,end_ms:int,text:string}> */
    private function chunkCues(GenerationChunk $chunk, float $base): array
    {
        $words = array_values(array_filter((array) $chunk->words, fn ($w) => isset($w['text'], $w['start_time'], $w['end_time']) && trim($w['text']) !== ''));
        if ($words === []) {
            return [];
        }

        // Locate each word in the chunk text so cue text keeps the original spacing and punctuation.
        $text = $chunk->text;
        $cursor = 0;
        foreach ($words as &$w) {
            $pos = mb_stripos($text, trim($w['text']), $cursor);
            if ($pos !== false && $pos - $cursor < 200) {
                $w['from'] = $pos;
                $w['to'] = $pos + mb_strlen(trim($w['text']));
                $cursor = $w['to'];
            }
        }
        unset($w);

        $groups = [];
        $current = [];
        foreach ($words as $w) {
            if ($current !== []) {
                $candidate = [...$current, $w];
                $tooLong = mb_strlen($this->groupText($candidate, $text)) > $this->maxChars;
                $tooSlow = ($w['end_time'] - $current[0]['start_time']) > $this->maxSeconds;
                if ($tooLong || $tooSlow) {
                    $groups[] = $current;
                    $current = [];
                }
            }
            $current[] = $w;

            $lastText = trim($w['text']);
            $sentenceEnd = (bool) preg_match('/[.!?…\x{17D4}\x{17D5}。！？]["\'”’»)\]]*$/u', $lastText);
            $clauseEnd = (bool) preg_match('/[,;:，、]$/u', $lastText) && mb_strlen($this->groupText($current, $text)) >= 42;
            if ($sentenceEnd || $clauseEnd) {
                $groups[] = $current;
                $current = [];
            }
        }
        if ($current !== []) {
            $groups[] = $current;
        }

        return array_map(fn ($g) => [
            'start_ms' => (int) round(($base + $g[0]['start_time']) * 1000),
            'end_ms' => (int) round(($base + end($g)['end_time']) * 1000),
            'text' => $this->groupText($g, $text),
        ], $groups);
    }

    private function groupText(array $group, string $text): string
    {
        $first = $group[0];
        $last = end($group);
        if (isset($first['from'], $last['to'])) {
            return trim(preg_replace('/\s+/u', ' ', mb_substr($text, $first['from'], $last['to'] - $first['from'])) ?? '');
        }

        return trim(implode(' ', array_map(fn ($w) => trim($w['text']), $group)));
    }
}
