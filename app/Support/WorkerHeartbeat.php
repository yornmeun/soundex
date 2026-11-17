<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * The queue worker writes a heartbeat on every loop (also while idle), so the UI
 * can tell the user when no worker is running instead of waiting forever.
 */
class WorkerHeartbeat
{
    private const KEY = 'kikitoons:worker-heartbeat';

    /** A worker that has not looped for this long is considered offline. */
    private const STALE_AFTER_SECONDS = 90;

    public static function beat(): void
    {
        Cache::put(self::KEY, now()->timestamp, self::STALE_AFTER_SECONDS * 2);
    }

    public static function lastSeen(): ?int
    {
        $ts = Cache::get(self::KEY);

        return is_numeric($ts) ? (int) $ts : null;
    }

    public static function isOnline(): bool
    {
        // A sync queue has no separate worker.
        if (config('queue.default') === 'sync') {
            return true;
        }

        $last = self::lastSeen();

        return $last !== null && now()->timestamp - $last <= self::STALE_AFTER_SECONDS;
    }
}
