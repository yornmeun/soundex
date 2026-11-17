<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateGeneralSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'model' => ['required', Rule::in(array_keys(config('kikitoons.models')))],
            'mp3_bitrate' => ['required', 'integer', Rule::in(config('kikitoons.mp3_bitrates'))],
            'chunk_max_chars' => ['required', 'integer', 'between:500,'.config('kikitoons.heygen.max_chars_per_request')],
            'silence_between_ms' => ['required', 'integer', 'between:0,5000'],
            'normalize_loudness' => ['nullable', 'boolean'],
            'trim_chunk_silence' => ['nullable', 'boolean'],
            'max_upload_kb' => ['required', 'integer', 'between:16,51200'],
        ];
    }
}
