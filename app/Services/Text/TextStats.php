<?php

namespace App\Services\Text;

class TextStats
{
    /** Khmer is written without spaces between words, so word counts under-estimate it. */
    private const KHMER = '/[\x{1780}-\x{17FF}\x{19E0}-\x{19FF}]/u';

    public function wordCount(string $text): int
    {
        $tokens = preg_split('/[\s\x{200B}]+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);

        return $tokens === false ? 0 : count($tokens);
    }

    public function characterCount(string $text): int
    {
        return mb_strlen($text);
    }

    public function containsKhmer(string $text): bool
    {
        return (bool) preg_match(self::KHMER, $text);
    }

    /**
     * Rough narration length estimate. This is only an estimate shown before
     * generation; the real duration is measured from the generated audio.
     */
    public function estimateSeconds(string $text, int $wordsPerMinute = 150): int
    {
        if ($this->containsKhmer($text)) {
            // ~12 characters per second for Khmer speech.
            return (int) ceil(mb_strlen(preg_replace('/\s+/u', '', $text) ?? $text) / 12);
        }

        return (int) ceil($this->wordCount($text) / max(1, $wordsPerMinute) * 60);
    }
}
