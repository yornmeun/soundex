<?php

namespace App\Support;

class Format
{
    public static function duration(?float $seconds): string
    {
        if ($seconds === null) {
            return '—';
        }

        $s = (int) round($seconds);
        $h = intdiv($s, 3600);
        $m = intdiv($s % 3600, 60);
        $sec = $s % 60;

        return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $sec) : sprintf('%d:%02d', $m, $sec);
    }

    public static function humanDuration(float $seconds): string
    {
        $s = (int) round($seconds);
        if ($s < 60) {
            return $s.'s';
        }
        $h = intdiv($s, 3600);
        $m = intdiv($s % 3600, 60);

        return $h > 0 ? "{$h}h {$m}m" : "{$m}m ".($s % 60).'s';
    }
}
