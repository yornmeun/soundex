<?php

namespace App\Services\Text;

/**
 * Detects character labels at the start of a script line:
 *   "Kiki: Hello!"   "Old Man: Hi."   "[Narrator] Once upon a time…"
 * Labels are only recognised at the beginning of a line (or subtitle), are at most
 * three words and contain no commas, so ordinary sentences are not mistaken for labels.
 */
class SpeakerParser
{
    private const BRACKET = '/^\s*\[(?<name>[^\]\n]{1,40})\]\s*/u';

    private const COLON = '/^\s*(?<name>[\p{L}\p{M}][\p{L}\p{M}\p{N}\'’.\-]*(?:[ ][\p{L}\p{M}\p{N}\'’.\-]+){0,2})\s*:\s+/u';

    /** @return array{key:string,label:string,rest:string,prefix:string}|null */
    public function detect(string $line): ?array
    {
        foreach ([self::BRACKET, self::COLON] as $pattern) {
            if (preg_match($pattern, $line, $m)) {
                $label = trim($m['name']);
                $rest = mb_substr($line, mb_strlen($m[0]));
                if ($label === '' || trim($rest) === '') {
                    continue;
                }

                return ['key' => self::key($label), 'label' => $label, 'rest' => $rest, 'prefix' => $m[0]];
            }
        }

        return null;
    }

    public static function key(string $label): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $label) ?? $label));
    }

    /**
     * Speakers found at line starts, in order of first appearance.
     *
     * @return list<array{key:string,label:string,lines:int}>
     */
    public function speakers(string $script): array
    {
        $found = [];
        foreach (preg_split('/\R/u', $script) ?: [] as $line) {
            if ($hit = $this->detect($line)) {
                $found[$hit['key']] ??= ['key' => $hit['key'], 'label' => $hit['label'], 'lines' => 0];
                $found[$hit['key']]['lines']++;
            }
        }

        return array_values($found);
    }

    /**
     * Split the script into speaker turns. Only labels present in $mapped become
     * character turns; every other line (including lines whose label is not
     * mapped) is narrator text and is kept exactly as written.
     *
     * @param  array<string,mixed>  $mapped  speaker key => voice data
     * @return list<array{speaker:?string,text:string}>
     */
    public function turns(string $script, array $mapped, bool $stripLabels): array
    {
        $turns = [];
        $script = str_replace(["\r\n", "\r"], "\n", $script);

        foreach (explode("\n", $script) as $line) {
            $speaker = null;
            $text = $line;

            $hit = $this->detect($line);
            if ($hit && array_key_exists($hit['key'], $mapped) && $mapped[$hit['key']] !== null) {
                $speaker = $hit['key'];
                $text = $stripLabels ? $hit['rest'] : $line;
            }

            $last = array_key_last($turns);
            if ($last !== null && $turns[$last]['speaker'] === $speaker) {
                $turns[$last]['text'] .= "\n".$text;
            } else {
                $turns[] = ['speaker' => $speaker, 'text' => $text];
            }
        }

        return array_values(array_filter(
            array_map(fn ($t) => ['speaker' => $t['speaker'], 'text' => trim($t['text'])], $turns),
            fn ($t) => $t['text'] !== '',
        ));
    }
}
