<?php

namespace App\Services\Srt;

use App\Services\Text\SpeakerParser;

/**
 * Groups subtitle cues into segments for Subtitle-Synchronized mode.
 * Each segment keeps the original start/end timestamps as metadata.
 */
class SegmentBuilder
{
    public const STRATEGY_SUBTITLE = 'subtitle';

    public const STRATEGY_SENTENCE = 'sentence';

    public function __construct(private readonly SpeakerParser $speakers = new SpeakerParser) {}

    private const SENTENCE_END = '/[.!?…\x{17D4}\x{17D5}。！？]["\'”’»)\]]*$/u';

    /**
     * @param  list<SrtCue>  $cues
     * @return list<array{text:string,start_ms:int,end_ms:int,subtitle_indices:list<int>}>
     */
    public function build(array $cues, string $strategy, int $maxChars): array
    {
        if ($strategy === self::STRATEGY_SUBTITLE) {
            return array_map(fn (SrtCue $cue) => [
                'text' => $cue->text,
                'start_ms' => $cue->startMs,
                'end_ms' => $cue->endMs,
                'subtitle_indices' => [$cue->index],
            ], $cues);
        }

        $segments = [];
        $group = [];

        foreach ($cues as $cue) {
            // A cue that starts with a character label ("Kiki: …") always starts a new segment.
            if ($group !== [] && $this->speakers->detect($cue->text) !== null) {
                $segments[] = $this->makeSegment($group);
                $group = [];
            }

            $candidate = [...$group, $cue];
            if ($group !== [] && mb_strlen($this->joinText($candidate)) > $maxChars) {
                $segments[] = $this->makeSegment($group);
                $candidate = [$cue];
            }
            $group = $candidate;

            if (preg_match(self::SENTENCE_END, $cue->text)) {
                $segments[] = $this->makeSegment($group);
                $group = [];
            }
        }

        if ($group !== []) {
            $segments[] = $this->makeSegment($group);
        }

        return $segments;
    }

    /** @param list<SrtCue> $group */
    private function makeSegment(array $group): array
    {
        return [
            'text' => $this->joinText($group),
            'start_ms' => $group[0]->startMs,
            'end_ms' => end($group)->endMs,
            'subtitle_indices' => array_map(fn (SrtCue $c) => $c->index, $group),
        ];
    }

    /** @param list<SrtCue> $group */
    private function joinText(array $group): string
    {
        return implode(' ', array_map(fn (SrtCue $c) => $c->text, $group));
    }
}
