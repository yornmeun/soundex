<?php

namespace App\Services\Srt;

class SrtWriter
{
    /** @param list<array{start_ms:int,end_ms:int,text:string}> $cues */
    public function write(array $cues): string
    {
        $out = [];
        $n = 0;
        foreach ($cues as $cue) {
            $text = trim((string) $cue['text']);
            if ($text === '') {
                continue;
            }
            $n++;
            $out[] = $n."\n".self::timestamp((int) $cue['start_ms']).' --> '.self::timestamp(max((int) $cue['start_ms'], (int) $cue['end_ms']))."\n".$text."\n";
        }

        return implode("\n", $out);
    }

    public static function timestamp(int $ms): string
    {
        $ms = max(0, $ms);

        return sprintf('%02d:%02d:%02d,%03d', intdiv($ms, 3600000), intdiv($ms % 3600000, 60000), intdiv($ms % 60000, 1000), $ms % 1000);
    }
}
