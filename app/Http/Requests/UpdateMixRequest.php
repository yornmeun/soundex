<?php

namespace App\Http\Requests;

use App\Models\AudioAsset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMixRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $userId = $this->user()->id;

        return [
            'music_asset_id' => ['nullable', 'integer', Rule::exists('audio_assets', 'id')->where('user_id', $userId)->where('type', AudioAsset::TYPE_MUSIC)],
            'music_volume_db' => ['nullable', 'numeric', 'between:-40,0'],
            'music_duck' => ['nullable', 'boolean'],
            'music_fade_in' => ['nullable', 'numeric', 'between:0,15'],
            'music_fade_out' => ['nullable', 'numeric', 'between:0,15'],
            'sfx' => ['nullable', 'array', 'max:100'],
            'sfx.*.asset_id' => ['required', 'integer', Rule::exists('audio_assets', 'id')->where('user_id', $userId)],
            'sfx.*.at_ms' => ['required', 'integer', 'min:0', 'max:86400000'],
            'sfx.*.volume_db' => ['nullable', 'numeric', 'between:-30,10'],
        ];
    }
}
