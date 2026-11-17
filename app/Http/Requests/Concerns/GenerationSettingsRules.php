<?php

namespace App\Http\Requests\Concerns;

use App\Models\AudioAsset;
use App\Models\VoiceProfile;
use Closure;
use Illuminate\Validation\Rule;

/** Validation shared by single generations and batches. */
trait GenerationSettingsRules
{
    protected function settingsRules(): array
    {
        $userId = $this->user()->id;

        return [
            // Local voice_profiles.id - the HeyGen voice ID never travels through the browser.
            'voice_profile_id' => ['required', 'integer', Rule::exists('voice_profiles', 'id')],
            'language' => ['required', Rule::in(['auto', ...array_keys(config('soundex.languages'))])],
            'model' => ['required', Rule::in(array_keys(config('soundex.models'))), $this->professionalVoiceModelRule()],
            'output_format' => ['required', Rule::in(array_keys(config('soundex.output_formats')))],
            'mp3_bitrate' => ['required_if:output_format,mp3', 'nullable', 'integer', Rule::in(config('soundex.mp3_bitrates'))],
            'speed' => ['nullable', 'numeric', 'between:0.75,1.25'],
            'silence_between_ms' => ['nullable', 'integer', 'between:0,5000'],
            'normalize_loudness' => ['nullable', 'boolean'],
            'trim_chunk_silence' => ['nullable', 'boolean'],
            'folder_id' => ['nullable', 'integer', Rule::exists('folders', 'id')->where('user_id', $userId)],
            // Character voices: [{key, label, voice_profile_id|null}]
            'speakers' => ['nullable', 'array', 'max:50'],
            'speakers.*.key' => ['required', 'string', 'max:64'],
            'speakers.*.label' => ['nullable', 'string', 'max:64'],
            'speakers.*.voice_profile_id' => ['nullable', 'integer', Rule::exists('voice_profiles', 'id')],
            'strip_speaker_labels' => ['nullable', 'boolean'],
            'use_pronunciations' => ['nullable', 'boolean'],
            'music_asset_id' => ['nullable', 'integer', Rule::exists('audio_assets', 'id')->where('user_id', $userId)->where('type', AudioAsset::TYPE_MUSIC)],
            'music_volume_db' => ['nullable', 'numeric', 'between:-40,0'],
            'music_duck' => ['nullable', 'boolean'],
        ];
    }

    /** Professional clones only work with heygen-voice-1 (Turbo models reject them). */
    private function professionalVoiceModelRule(): Closure
    {
        return function (string $attribute, mixed $model, Closure $fail) {
            if ($model === 'heygen-voice-1') {
                return;
            }
            $ids = array_filter([$this->input('voice_profile_id'), ...array_column((array) $this->input('speakers', []), 'voice_profile_id')]);
            if ($ids && VoiceProfile::query()->whereIn('id', $ids)->where('mode', 'professional')->exists()) {
                $fail('Professional voice clones only work with the “HeyGen Voice 1” model.');
            }
        };
    }
}
