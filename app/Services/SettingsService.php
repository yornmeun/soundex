<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Application settings stored in the `settings` table. Secrets are encrypted
 * at rest with the APP_KEY and are never sent back to the browser in full.
 */
class SettingsService
{
    public const API_KEY = 'heygen.api_key';

    public const DEFAULT_VOICE_ID = 'voice.default_voice_id';

    public const AZURE_KEY = 'azure.key';

    public const AZURE_REGION = 'azure.region';

    /** Keys editable in General / API settings, with their config defaults. */
    public function defaults(): array
    {
        $d = config('soundex.defaults');

        return [
            'generation.language' => $d['language'],
            'generation.output_format' => $d['output_format'],
            'generation.mp3_bitrate' => $d['mp3_bitrate'],
            'generation.model' => config('soundex.heygen.default_model'),
            'generation.chunk_max_chars' => $d['chunk_max_chars'],
            'generation.silence_between_ms' => $d['silence_between_ms'],
            'generation.normalize_loudness' => $d['normalize_loudness'],
            'generation.trim_chunk_silence' => $d['trim_chunk_silence'],
            'upload.max_kb' => $d['max_upload_kb'],
        ];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $setting = Setting::query()->where('key', $key)->first();

        if (! $setting || $setting->value === null) {
            return $default ?? ($this->defaults()[$key] ?? null);
        }

        if ($setting->is_encrypted) {
            try {
                return Crypt::decryptString($setting->value);
            } catch (DecryptException) {
                Log::error("Setting {$key} could not be decrypted (APP_KEY changed?).");

                return $default;
            }
        }

        return json_decode($setting->value, true);
    }

    public function set(string $key, mixed $value, bool $encrypt = false): void
    {
        if ($value === null) {
            Setting::query()->where('key', $key)->delete();

            return;
        }

        Setting::query()->updateOrCreate(['key' => $key], [
            'value' => $encrypt ? Crypt::encryptString((string) $value) : json_encode($value),
            'is_encrypted' => $encrypt,
        ]);
    }

    /** @return array<string,mixed> */
    public function generationDefaults(): array
    {
        $out = [];
        foreach (array_keys($this->defaults()) as $key) {
            $out[$key] = $this->get($key);
        }

        return $out;
    }

    public function apiKey(): ?string
    {
        $stored = $this->get(self::API_KEY);

        return filled($stored) ? (string) $stored : (config('soundex.heygen.api_key') ?: null);
    }

    public function apiKeySource(): ?string
    {
        if (filled($this->get(self::API_KEY))) {
            return 'database';
        }

        return filled(config('soundex.heygen.api_key')) ? 'env' : null;
    }

    public function maskedApiKey(): ?string
    {
        return self::mask($this->apiKey());
    }

    public function setApiKey(?string $key): void
    {
        $this->set(self::API_KEY, filled($key) ? trim($key) : null, encrypt: true);
    }

    public function azureKey(): ?string
    {
        $stored = $this->get(self::AZURE_KEY);

        return filled($stored) ? (string) $stored : (config('soundex.azure.key') ?: null);
    }

    public function azureKeySource(): ?string
    {
        if (filled($this->get(self::AZURE_KEY))) {
            return 'database';
        }

        return filled(config('soundex.azure.key')) ? 'env' : null;
    }

    public function setAzureKey(?string $key): void
    {
        $this->set(self::AZURE_KEY, filled($key) ? trim($key) : null, encrypt: true);
    }

    /** Azure region such as "southeastasia" (lower-case, no spaces). */
    public function azureRegion(): ?string
    {
        $region = $this->get(self::AZURE_REGION) ?: config('soundex.azure.region');

        return filled($region) ? strtolower(str_replace(' ', '', (string) $region)) : null;
    }

    public function setAzureRegion(?string $region): void
    {
        $this->set(self::AZURE_REGION, filled($region) ? strtolower(str_replace(' ', '', trim($region))) : null);
    }

    public function hasAzure(): bool
    {
        return filled($this->azureKey()) && filled($this->azureRegion());
    }

    /** True when at least one speech provider can be used. */
    public function hasAnyProvider(): bool
    {
        return filled($this->apiKey()) || $this->hasAzure();
    }

    public function defaultVoiceId(): ?string
    {
        return $this->get(self::DEFAULT_VOICE_ID) ?: (config('soundex.heygen.default_voice_id') ?: null);
    }

    public function setDefaultVoiceId(?string $voiceId): void
    {
        $this->set(self::DEFAULT_VOICE_ID, $voiceId);
    }

    public static function mask(?string $secret): ?string
    {
        if (blank($secret)) {
            return null;
        }

        $len = strlen($secret);
        if ($len <= 8) {
            return str_repeat('•', $len);
        }

        return substr($secret, 0, 4).str_repeat('•', min(12, $len - 8)).substr($secret, -4);
    }
}
