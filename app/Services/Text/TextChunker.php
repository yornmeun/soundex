<?php

namespace App\Services\Text;

use InvalidArgumentException;

/**
 * Splits long narration text into ordered chunks no longer than $maxChars.
 *
 * Preference order for split points:
 *   paragraphs/sentences -> clauses (, ; :) -> words -> grapheme clusters.
 * Every splitter keeps the original separators, so joining the chunks gives
 * back the original text (apart from whitespace trimmed at chunk edges).
 */
class TextChunker
{
    /** Sentence terminators followed by optional closing quotes/brackets and whitespace, or Khmer/CJK terminators. */
    private const SENTENCE_DELIMITER = '/([.!?…]+["\'”’»)\]]*\s+|[\x{17D4}\x{17D5}。！？]+["\'”’»)\]]*\s*|\n\s*\n\s*)/u';

    private const CLAUSE_DELIMITER = '/([,;:،、，；]\s+)/u';

    /** @return list<string> */
    public function chunk(string $text, int $maxChars): array
    {
        if ($maxChars < 1) {
            throw new InvalidArgumentException('maxChars must be positive.');
        }

        $text = str_replace(["\r\n", "\r"], "\n", $text);
        if (trim($text) === '') {
            return [];
        }

        $chunks = $this->split($text, $maxChars, 0);

        return array_values(array_filter(array_map('trim', $chunks), fn ($c) => $c !== ''));
    }

    /** @return list<string> */
    private function split(string $text, int $max, int $level): array
    {
        if ($this->len($text) <= $max) {
            return [$text];
        }

        $segments = match ($level) {
            0 => $this->splitKeepingDelimiters($text, self::SENTENCE_DELIMITER),
            1 => $this->splitKeepingDelimiters($text, self::CLAUSE_DELIMITER),
            2 => $this->words($text),
            default => $this->graphemes($text),
        };

        if (count($segments) <= 1 && $level < 3) {
            return $this->split($text, $max, $level + 1);
        }

        $out = [];
        $buffer = '';

        foreach ($segments as $segment) {
            if ($this->len($segment) > $max) {
                if ($buffer !== '') {
                    $out[] = $buffer;
                    $buffer = '';
                }
                array_push($out, ...$this->split($segment, $max, $level + 1));

                continue;
            }

            if ($buffer === '' || $this->len($buffer.$segment) <= $max) {
                $buffer .= $segment;
            } else {
                $out[] = $buffer;
                $buffer = $segment;
            }
        }

        if ($buffer !== '') {
            $out[] = $buffer;
        }

        return $out;
    }

    /** Split while attaching each delimiter to the piece before it. */
    private function splitKeepingDelimiters(string $text, string $pattern): array
    {
        $parts = preg_split($pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return [$text];
        }

        $segments = [];
        for ($i = 0, $n = count($parts); $i < $n; $i += 2) {
            $piece = $parts[$i].($parts[$i + 1] ?? '');
            if ($piece !== '') {
                $segments[] = $piece;
            }
        }

        return $segments;
    }

    private function words(string $text): array
    {
        $parts = preg_split('/(?<=[\s\x{200B}])(?=[^\s\x{200B}])/u', $text);

        return $parts === false ? [$text] : $parts;
    }

    /** Never cut inside a grapheme cluster (important for Khmer vowel signs and subscripts). */
    private function graphemes(string $text): array
    {
        preg_match_all('/\X/u', $text, $m);

        return $m[0] ?: [$text];
    }

    private function len(string $text): int
    {
        return mb_strlen(trim($text));
    }
}
