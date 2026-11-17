<?php

namespace App\Services\Srt;

/**
 * Maps an edited script back onto the original subtitle cues ("corrected SRT").
 * Original timings are kept; the words of the edited script are assigned to cues
 * with a word-level Myers diff:
 *  - unchanged words stay in their cue,
 *  - replaced words take the cue(s) of the words they replace,
 *  - inserted words join the cue of the preceding word.
 */
class SubtitleAligner
{
    /** Above this edit distance, fall back to proportional distribution. */
    private const MAX_EDIT_DISTANCE = 4000;

    /**
     * @param  list<SrtCue>  $cues
     * @return list<array{start_ms:int,end_ms:int,text:string}>
     */
    public function align(array $cues, string $editedScript): array
    {
        $original = [];
        $owner = [];
        foreach ($cues as $ci => $cue) {
            foreach ($this->tokens($cue->text) as $token) {
                $original[] = $token;
                $owner[] = $ci;
            }
        }
        $edited = $this->tokens($editedScript);

        $assigned = $this->assign($original, $owner, $edited, count($cues));

        $buckets = array_fill(0, count($cues), []);
        foreach ($edited as $j => $token) {
            $buckets[$assigned[$j]][] = $token;
        }

        $out = [];
        foreach ($cues as $ci => $cue) {
            $out[] = ['start_ms' => $cue->startMs, 'end_ms' => $cue->endMs, 'text' => implode(' ', $buckets[$ci])];
        }

        return array_values(array_filter($out, fn ($c) => $c['text'] !== ''));
    }

    /** @return list<string> */
    private function tokens(string $text): array
    {
        return preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /** @return list<int> cue index for every edited token */
    private function assign(array $a, array $owner, array $b, int $cueCount): array
    {
        $n = count($a);
        $m = count($b);
        if ($m === 0) {
            return [];
        }
        if ($n === 0) {
            return array_fill(0, $m, 0);
        }

        $ops = $this->diff($a, $b);
        if ($ops === null) {
            // Too different: spread edited words over cues proportionally to their original size.
            return array_map(fn ($j) => $owner[min($n - 1, intdiv($j * $n, $m))], range(0, $m - 1));
        }

        $result = [];
        $i = 0;
        $j = 0;
        $lastCue = null;
        $k = 0;
        $count = count($ops);

        while ($k < $count) {
            if ($ops[$k] === '=') {
                $result[$j] = $owner[$i];
                $lastCue = $owner[$i];
                $i++;
                $j++;
                $k++;

                continue;
            }

            // Collect a run of deletions/insertions (a "hunk").
            $deleted = [];
            $inserted = [];
            while ($k < $count && $ops[$k] !== '=') {
                if ($ops[$k] === '-') {
                    $deleted[] = $owner[$i++];
                } else {
                    $inserted[] = $j++;
                }
                $k++;
            }

            $d = count($deleted);
            foreach ($inserted as $idx => $jj) {
                if ($d > 0) {
                    $result[$jj] = $deleted[min($d - 1, intdiv($idx * $d, max(1, count($inserted))))];
                } else {
                    $result[$jj] = $lastCue ?? ($i < $n ? $owner[$i] : $cueCount - 1);
                }
            }
            if ($d > 0) {
                $lastCue = end($deleted);
            }
        }

        ksort($result);

        return array_values($result);
    }

    /**
     * Myers O((N+M)D) diff. Returns a list of '=', '-', '+' operations, or null
     * when the inputs are too different.
     *
     * @return list<string>|null
     */
    private function diff(array $a, array $b): ?array
    {
        // Strip the common prefix/suffix first - edits are usually local.
        $prefix = 0;
        $n = count($a);
        $m = count($b);
        while ($prefix < $n && $prefix < $m && $a[$prefix] === $b[$prefix]) {
            $prefix++;
        }
        $suffix = 0;
        while ($suffix < $n - $prefix && $suffix < $m - $prefix && $a[$n - 1 - $suffix] === $b[$m - 1 - $suffix]) {
            $suffix++;
        }

        $midA = array_slice($a, $prefix, $n - $prefix - $suffix);
        $midB = array_slice($b, $prefix, $m - $prefix - $suffix);

        $middle = $this->myers($midA, $midB);
        if ($middle === null) {
            return null;
        }

        return [...array_fill(0, $prefix, '='), ...$middle, ...array_fill(0, $suffix, '=')];
    }

    private function myers(array $a, array $b): ?array
    {
        $n = count($a);
        $m = count($b);
        if ($n === 0) {
            return array_fill(0, $m, '+');
        }
        if ($m === 0) {
            return array_fill(0, $n, '-');
        }

        $max = $n + $m;
        if (min($max, self::MAX_EDIT_DISTANCE) < abs($n - $m)) {
            return null;
        }

        $v = [1 => 0];
        $trace = [];

        for ($d = 0; $d <= min($max, self::MAX_EDIT_DISTANCE); $d++) {
            $trace[] = $v;
            for ($k = -$d; $k <= $d; $k += 2) {
                if ($k === -$d || ($k !== $d && ($v[$k - 1] ?? -1) < ($v[$k + 1] ?? -1))) {
                    $x = $v[$k + 1] ?? 0;
                } else {
                    $x = ($v[$k - 1] ?? 0) + 1;
                }
                $y = $x - $k;
                while ($x < $n && $y < $m && $a[$x] === $b[$y]) {
                    $x++;
                    $y++;
                }
                $v[$k] = $x;
                if ($x >= $n && $y >= $m) {
                    return $this->backtrack($trace, $n, $m);
                }
            }
        }

        return null;
    }

    private function backtrack(array $trace, int $x, int $y): array
    {
        $ops = [];
        for ($d = count($trace) - 1; $d >= 0; $d--) {
            $v = $trace[$d];
            $k = $x - $y;
            if ($k === -$d || ($k !== $d && ($v[$k - 1] ?? -1) < ($v[$k + 1] ?? -1))) {
                $prevK = $k + 1;
            } else {
                $prevK = $k - 1;
            }
            $prevX = $v[$prevK] ?? 0;
            $prevY = $prevX - $prevK;

            while ($x > $prevX && $y > $prevY) {
                $ops[] = '=';
                $x--;
                $y--;
            }
            if ($d > 0) {
                $ops[] = $x === $prevX ? '+' : '-';
            }
            $x = $prevX;
            $y = $prevY;
        }

        return array_reverse($ops);
    }
}
