<?php

namespace App\Services\HeyGen;

use App\Services\SettingsService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;

/**
 * HeyGen Voice API client.
 *
 * Endpoints (verified against developers.heygen.com):
 *  - POST /v3/models/audio/tts             synchronous TTS, returns data.audio_url + data.duration
 *  - GET  /v3/models/audio/voices          workspace (type=private) voices, paginated by next_token
 *  - GET  /v3/models/audio/voices/{id}     one voice, with a fresh preview_audio_url
 *  - GET  /v3/users/me                     verify credentials
 *
 * The API key is never logged or returned to the browser.
 */
class HeyGenService
{
    private const RATE_LIMIT_KEY = 'heygen:requests';

    public function __construct(private readonly SettingsService $settings) {}

    public function hasApiKey(): bool
    {
        return filled($this->settings->apiKey());
    }

    /** @return array{username:?string,email:?string,billing_type:?string,raw:array} */
    public function testConnection(?string $apiKey = null): array
    {
        $response = $this->send(fn (PendingRequest $http) => $http->get('/v3/users/me'), $apiKey);
        $data = (array) $response->json('data', []);

        return [
            'username' => $data['username'] ?? null,
            'email' => $data['email'] ?? null,
            'billing_type' => $data['billing_type'] ?? null,
            'raw' => $data,
        ];
    }

    /**
     * All voices created in the caller's workspace (instant + professional clones).
     *
     * @return list<array<string,mixed>>
     */
    public function listPrivateVoices(): array
    {
        $voices = [];
        $token = null;
        $pages = 0;

        do {
            $query = array_filter(['type' => 'private', 'limit' => 100, 'token' => $token]);
            $response = $this->send(fn (PendingRequest $http) => $http->get('/v3/models/audio/voices', $query));
            array_push($voices, ...(array) $response->json('data', []));
            $token = $response->json('has_more') ? $response->json('next_token') : null;
        } while ($token && ++$pages < 50);

        return $voices;
    }

    /** @return array<string,mixed> */
    public function getVoice(string $voiceId): array
    {
        $response = $this->send(fn (PendingRequest $http) => $http->get('/v3/models/audio/voices/'.rawurlencode($voiceId)));

        return (array) $response->json('data', $response->json());
    }

    /**
     * Synthesize one piece of text. Throws HeyGenException on any failure; the
     * caller decides whether to retry using HeyGenException::isRetryable().
     *
     * @param  array{model?:string,language?:?string,output_format?:string,mp3_bitrate?:?int,speed?:?float,pronunciation_dictionary?:array<string,string>,with_timestamps?:bool}  $options
     */
    public function synthesize(string $voiceId, string $text, array $options = []): TtsResult
    {
        $maxChars = (int) config('soundex.heygen.max_chars_per_request');
        if (mb_strlen($text) > $maxChars) {
            throw new HeyGenException("Text exceeds HeyGen's {$maxChars} character limit per request.", HeyGenException::CATEGORY_INVALID);
        }

        $payload = [
            'voice_id' => $voiceId,
            'text' => $text,
            'model' => $options['model'] ?? config('soundex.heygen.default_model'),
        ];

        if (! empty($options['language'])) {
            $payload['language'] = $options['language'];
        }

        // Omitting output_format returns WAV (PCM16, 44.1 kHz mono).
        if (($options['output_format'] ?? 'mp3') === 'mp3') {
            $payload['output_format'] = [
                'encoding' => 'mp3',
                'sample_rate_hertz' => 44100,
                'bitrate_bps' => (int) ($options['mp3_bitrate'] ?? 128000),
            ];
        }

        // speed is documented for professional clones only (0.75 - 1.25).
        if (isset($options['speed']) && $options['speed'] !== null) {
            $payload['speed'] = round(max(0.75, min(1.25, (float) $options['speed'])), 2);
        }

        // Term => IPA ("/.../"); only sent when non-empty (instant voices, Voice 1 / Turbo).
        if (! empty($options['pronunciation_dictionary'])) {
            $payload['pronunciation_dictionary'] = array_slice($options['pronunciation_dictionary'], 0, 100, true);
        }

        // Word timings are used to build narration-timed subtitles.
        if (! empty($options['with_timestamps'])) {
            $payload['with_timestamps'] = true;
        }

        $this->throttle();

        $started = microtime(true);
        $response = $this->send(
            fn (PendingRequest $http) => $http->timeout((int) config('soundex.heygen.request_timeout'))
                ->post('/v3/models/audio/tts', $payload),
        );

        $audioUrl = $response->json('data.audio_url');
        if (! is_string($audioUrl) || $audioUrl === '') {
            throw new HeyGenException('HeyGen returned no audio_url.', HeyGenException::CATEGORY_TRANSIENT, $response->status());
        }

        $requestId = $response->header('X-Request-Id') ?: null;

        Log::info('HeyGen TTS completed', [
            'chars' => mb_strlen($text),
            'model' => $payload['model'],
            'pronunciations' => count($payload['pronunciation_dictionary'] ?? []),
            'duration' => $response->json('data.duration'),
            'request_id' => $requestId,
            'elapsed_ms' => (int) ((microtime(true) - $started) * 1000),
        ]);

        $words = array_values(array_map(fn ($w) => [
            'text' => (string) ($w['text'] ?? ''),
            'start_time' => (float) ($w['start_time'] ?? 0),
            'end_time' => (float) ($w['end_time'] ?? 0),
        ], array_filter((array) $response->json('data.original_words', []), 'is_array')));

        return new TtsResult($audioUrl, (float) $response->json('data.duration', 0), $requestId, $words);
    }

    /** Download a generated audio file (pre-signed URL; no API key is sent). */
    public function downloadAudio(string $url, string $absolutePath): void
    {
        if (! is_dir(dirname($absolutePath))) {
            mkdir(dirname($absolutePath), 0775, true);
        }

        try {
            $response = Http::connectTimeout((int) config('soundex.heygen.connect_timeout'))
                ->timeout(300)
                ->sink($absolutePath)
                ->get($url);
        } catch (ConnectionException $e) {
            @unlink($absolutePath);
            throw new HeyGenException('Could not download generated audio: '.$e->getMessage(), HeyGenException::CATEGORY_TRANSIENT, previous: $e);
        }

        if ($response->failed() || ! is_file($absolutePath) || filesize($absolutePath) === 0) {
            @unlink($absolutePath);
            throw new HeyGenException('Could not download generated audio (HTTP '.$response->status().').', HeyGenException::CATEGORY_TRANSIENT, $response->status());
        }
    }

    /**
     * Client-side throttle to stay under the documented 30 requests/minute.
     */
    private function throttle(): void
    {
        $perMinute = max(1, (int) config('soundex.heygen.requests_per_minute'));

        while (RateLimiter::tooManyAttempts(self::RATE_LIMIT_KEY, $perMinute)) {
            Sleep::for(max(1, RateLimiter::availableIn(self::RATE_LIMIT_KEY)))->seconds();
        }

        RateLimiter::hit(self::RATE_LIMIT_KEY, 60);
    }

    /** @param callable(PendingRequest): Response $call */
    private function send(callable $call, ?string $apiKey = null): Response
    {
        $apiKey ??= $this->settings->apiKey();
        if (blank($apiKey)) {
            throw HeyGenException::missingApiKey();
        }

        $http = Http::baseUrl(rtrim((string) config('soundex.heygen.base_url'), '/'))
            ->withHeaders(['X-Api-Key' => $apiKey])
            ->acceptJson()
            ->asJson()
            ->connectTimeout((int) config('soundex.heygen.connect_timeout'))
            ->timeout(60);

        try {
            $response = $call($http);
        } catch (ConnectionException $e) {
            Log::warning('HeyGen connection problem', ['error' => $e->getMessage()]);
            throw new HeyGenException('Could not reach HeyGen (network error or timeout).', HeyGenException::CATEGORY_TRANSIENT, previous: $e);
        }

        if ($response->successful()) {
            return $response;
        }

        $retryAfter = $response->header('Retry-After');
        $exception = HeyGenException::fromStatus(
            $response->status(),
            $response->json('error.code'),
            $response->json('error.message') ?? $response->json('message'),
            is_numeric($retryAfter) ? (int) $retryAfter : null,
            $response->header('X-Request-Id') ?: $response->json('error.request_id'),
        );

        Log::warning('HeyGen API error', [
            'status' => $response->status(),
            'code' => $exception->errorCode,
            'category' => $exception->category,
            'request_id' => $exception->requestId,
        ]);

        throw $exception;
    }
}
