<?php

namespace App\Services;

use App\Models\PronunciationEntry;
use App\Models\User;

/**
 * HeyGen `pronunciation_dictionary` (POST /v3/models/audio/tts):
 *  - up to 100 entries; keys 1-128 chars; values 3-512 chars, IPA wrapped in slashes
 *  - case-insensitive whole-term matching; up to 100 pronunciation occurrences per request
 *  - applies to instant voices on heygen-voice-1 and heygen-voice-1-turbo only
 */
class PronunciationService
{
    public const MAX_ENTRIES_PER_REQUEST = 100;

    public const MAX_OCCURRENCES_PER_REQUEST = 100;

    /** @return array<string,string> term => IPA */
    public function dictionaryFor(User $user): array
    {
        return PronunciationEntry::query()->ownedBy($user)->where('is_active', true)
            ->orderBy('term')->pluck('ipa', 'term')->all();
    }

    public function isSupported(?string $voiceMode, string $model): bool
    {
        // Professional clones and Microsoft voices don't accept HeyGen's pronunciation dictionary.
        return ! in_array($voiceMode, ['professional', 'azure'], true)
            && in_array($model, ['heygen-voice-1', 'heygen-voice-1-turbo'], true);
    }

    public function occurrences(string $term, string $text): int
    {
        return preg_match_all($this->pattern($term), $text);
    }

    /**
     * Only the entries that appear in $text, kept within HeyGen's per-request limits.
     * Longer terms win when the occurrence budget runs out.
     *
     * @param  array<string,string>  $dictionary
     * @return array<string,string>
     */
    public function forText(array $dictionary, string $text): array
    {
        $hits = [];
        foreach ($dictionary as $term => $ipa) {
            $count = $this->occurrences((string) $term, $text);
            if ($count > 0) {
                $hits[] = ['term' => (string) $term, 'ipa' => $ipa, 'count' => $count];
            }
        }

        usort($hits, fn ($a, $b) => mb_strlen($b['term']) <=> mb_strlen($a['term']));

        $out = [];
        $budget = self::MAX_OCCURRENCES_PER_REQUEST;
        foreach ($hits as $hit) {
            if (count($out) >= self::MAX_ENTRIES_PER_REQUEST || $hit['count'] > $budget) {
                continue;
            }
            $out[$hit['term']] = $hit['ipa'];
            $budget -= $hit['count'];
        }

        return $out;
    }

    private function pattern(string $term): string
    {
        return '/(?<![\p{L}\p{M}\p{N}])'.preg_quote($term, '/').'(?![\p{L}\p{M}\p{N}])/iu';
    }
}
