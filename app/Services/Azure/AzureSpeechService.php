<?php

namespace App\Services\Azure;

use App\Services\HeyGen\HeyGenException;
use App\Services\HeyGen\TtsResult;
use App\Services\SettingsService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;

/**
 * Microsoft Azure Speech text-to-speech (REST API).
 *
 *  - GET  https://{region}.tts.speech.microsoft.com/cognitiveservices/voices/list
 *  - POST https://{region}.tts.speech.microsoft.com/cognitiveservices/v1   SSML in, audio bytes out
 *
 * Errors are thrown as HeyGenException (the app's generic speech-provider exception) so the
 * generation pipeline's retry / back-off logic works the same for both providers.
 * The key is never logged or returned to the browser.
 */
class AzureSpeechService
{
    public const MODE = 'azure';

    private const RATE_LIMIT_KEY = 'azure-speech:requests';

    public function __construct(private readonly SettingsService $settings) {}

    public function isConfigured(): bool
    {
        return $this->settings->hasAzure();
    }

    /** Verify the key and region by loading the voice list (no characters are billed). */
    public function testConnection(?string $key = null, ?string $region = null): int
    {
        return count($this->listVoices(['*'], $key, $region));
    }

    /**
     * Neural voices for the given locales ("km-KH", "en-US", or "*" for all).
     *
     * @param  list<string>|null  $locales
     * @return list<array{voice_id:string,name:string,language:string,gender:?string}>
     */
    public function listVoices(?array $locales = null, ?string $key = null, ?string $region = null): array
    {
        $locales ??= (array) config('soundex.azure.voice_locales');
        $all = in_array('*', $locales, true) || $locales === [];
        $wanted = array_map('strtolower', $locales);

        $response = $this->send(fn (PendingRequest $http) => $http->get('/cognitiveservices/voices/list'), $key, $region);

        $voices = [];
        foreach ((array) $response->json() as $voice) {
            if (! is_array($voice) || empty($voice['ShortName'])) {
                continue;
            }
            $locale = (string) ($voice['Locale'] ?? '');
            if (! $all && ! in_array(strtolower($locale), $wanted, true)) {
                continue;
            }
            if (isset($voice['Status']) && strcasecmp((string) $voice['Status'], 'Deprecated') === 0) {
                continue;
            }

            $voices[] = [
                'voice_id' => (string) $voice['ShortName'],
                'name' => trim(($voice['DisplayName'] ?? $voice['ShortName']).' · '.($voice['LocaleName'] ?? $locale)),
                'language' => $locale,
                'gender' => isset($voice['Gender']) ? strtolower((string) $voice['Gender']) : null,
            ];
        }

        return $voices;
    }

    /**
     * Synthesize $text with an Azure voice straight into $absolutePath.
     * Azure returns no word timings over REST, so TtsResult::$words is empty.
     *
     * @param  array{output_format?:string,mp3_bitrate?:?int,speed?:?float}  $options
     */
    public function synthesizeTo(string $voiceName, string $text, string $absolutePath, array $options = []): TtsResult
    {
        $maxChars = (int) config('soundex.azure.max_chars_per_request');
        if (mb_strlen($text) > $maxChars) {
            throw new HeyGenException("Text exceeds the {$maxChars} character limit per Microsoft Speech request.", HeyGenException::CATEGORY_INVALID);
        }

        $format = self::outputFormat($options['output_format'] ?? 'mp3', $options['mp3_bitrate'] ?? null);
        $ssml = self::ssml($voiceName, $text, $options['speed'] ?? null);

        $this->throttle();
        $started = microtime(true);

        $response = $this->send(fn (PendingRequest $http) => $http
            ->timeout((int) config('soundex.azure.request_timeout'))
            ->withHeaders(['X-Microsoft-OutputFormat' => $format])
            ->withBody($ssml, 'application/ssml+xml')
            ->post('/cognitiveservices/v1'));

        $audio = $response->body();
        if ($audio === '') {
            throw new HeyGenException('Microsoft Speech returned no audio.', HeyGenException::CATEGORY_TRANSIENT, $response->status());
        }

        if (! is_dir(dirname($absolutePath))) {
            mkdir(dirname($absolutePath), 0775, true);
        }
        file_put_contents($absolutePath, $audio);

        $requestId = $response->header('X-RequestId') ?: null;
        Log::info('Azure TTS completed', [
            'chars' => mb_strlen($text),
            'voice' => $voiceName,
            'bytes' => strlen($audio),
            'request_id' => $requestId,
            'elapsed_ms' => (int) ((microtime(true) - $started) * 1000),
        ]);

        return new TtsResult('', 0.0, $requestId);
    }

    /** A short sample in the voice's own language, for the Preview button. */
    public static function previewText(string $voiceName): string
    {
        return match (strtolower(substr($voiceName, 0, 2))) {
            'km' => 'សួស្តី! នេះជាសំឡេងគំរូរបស់ខ្ញុំ។ ខ្ញុំអាចអានរឿងរបស់អ្នកជាភាសាខ្មែរ។',
            default => 'Hello! This is a short preview of my voice. I can read your stories out loud.',
        };
    }

    /** "km-KH-SreymomNeural" -> "km-KH" */
    public static function localeOf(string $voiceName): string
    {
        $parts = explode('-', $voiceName);

        return count($parts) >= 3 ? $parts[0].'-'.$parts[1] : 'en-US';
    }

    public static function outputFormat(string $format, ?int $mp3Bitrate): string
    {
        if ($format === 'wav') {
            return 'riff-44100hz-16bit-mono-pcm'; // same as HeyGen's WAV
        }

        // Azure has no 44.1 kHz MP3; 48 kHz is the closest high-quality option.
        return ($mp3Bitrate ?? 128000) <= 96000 ? 'audio-48khz-96kbitrate-mono-mp3' : 'audio-48khz-192kbitrate-mono-mp3';
    }

    public static function ssml(string $voiceName, string $text, ?float $speed = null): string
    {
        $escape = fn (string $s) => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        // Paragraph breaks become a short pause, like a narrator would take.
        $body = implode('<break time="600ms"/>', array_map($escape, preg_split('/\n\s*\n/u', trim($text)) ?: [$text]));

        if ($speed !== null && abs($speed - 1.0) >= 0.01) {
            $percent = (int) round(($speed - 1) * 100);
            $body = '<prosody rate="'.($percent > 0 ? '+' : '').$percent.'%">'.$body.'</prosody>';
        }

        return '<speak version="1.0" xmlns="http://www.w3.org/2001/10/synthesis" xml:lang="'.$escape(self::localeOf($voiceName)).'">'
            .'<voice name="'.$escape($voiceName).'">'.$body.'</voice></speak>';
    }

    /** Stay under the subscription's request rate (20/min on the free tier). */
    private function throttle(): void
    {
        $perMinute = max(1, (int) config('soundex.azure.requests_per_minute'));

        while (RateLimiter::tooManyAttempts(self::RATE_LIMIT_KEY, $perMinute)) {
            Sleep::for(max(1, RateLimiter::availableIn(self::RATE_LIMIT_KEY)))->seconds();
        }

        RateLimiter::hit(self::RATE_LIMIT_KEY, 60);
    }

    /** @param callable(PendingRequest): Response $call */
    private function send(callable $call, ?string $key = null, ?string $region = null): Response
    {
        $key ??= $this->settings->azureKey();
        $region ??= $this->settings->azureRegion();
        if (blank($key) || blank($region)) {
            throw new HeyGenException('Microsoft Speech is not configured. Add the key and region in API Settings.', HeyGenException::CATEGORY_CONFIG);
        }
        if (! preg_match('/^[a-z0-9]+$/', $region)) {
            throw new HeyGenException('The Microsoft Speech region looks wrong. Use the short name, e.g. "southeastasia".', HeyGenException::CATEGORY_CONFIG);
        }

        $http = Http::baseUrl("https://{$region}.tts.speech.microsoft.com")
            ->withHeaders(['Ocp-Apim-Subscription-Key' => $key, 'User-Agent' => 'soundex'])
            ->connectTimeout((int) config('soundex.azure.connect_timeout'))
            ->timeout(60);

        try {
            $response = $call($http);
        } catch (ConnectionException $e) {
            Log::warning('Azure Speech connection problem', ['error' => $e->getMessage()]);
            throw new HeyGenException('Could not reach Microsoft Speech (network error, timeout, or wrong region).', HeyGenException::CATEGORY_TRANSIENT, previous: $e);
        }

        if ($response->successful()) {
            return $response;
        }

        $status = $response->status();
        $category = match (true) {
            $status === 429 => HeyGenException::CATEGORY_RATE_LIMIT,
            in_array($status, [401, 403], true) => HeyGenException::CATEGORY_AUTH,
            $status >= 500 || $status === 408 => HeyGenException::CATEGORY_TRANSIENT,
            default => HeyGenException::CATEGORY_INVALID,
        };
        $message = match ($category) {
            HeyGenException::CATEGORY_RATE_LIMIT => 'Microsoft Speech rate limit reached.',
            HeyGenException::CATEGORY_AUTH => 'Microsoft Speech rejected the key. Check the key and region in API Settings.',
            HeyGenException::CATEGORY_TRANSIENT => 'Microsoft Speech is temporarily unavailable.',
            default => 'Microsoft Speech could not process the request (check the voice and text).',
        };
        $detail = trim(mb_substr(strip_tags($response->body()), 0, 200));
        $retryAfter = $response->header('Retry-After');

        Log::warning('Azure Speech API error', ['status' => $status, 'category' => $category]);

        throw new HeyGenException(
            $message.($detail !== '' ? " ({$detail})" : '')." [HTTP {$status}]",
            $category, $status, null, is_numeric($retryAfter) ? (int) $retryAfter : null, $response->header('X-RequestId') ?: null,
        );
    }
}
