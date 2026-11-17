<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateApiSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            // Leave blank to keep the stored key.
            'api_key' => ['nullable', 'string', 'min:10', 'max:500', 'regex:/^\S+$/'],
            'remove_api_key' => ['nullable', 'boolean'],
            'default_voice_id' => ['nullable', 'string', 'max:128', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'default_language' => ['required', Rule::in(['auto', ...array_keys(config('soundex.languages'))])],
            'default_output_format' => ['required', Rule::in(array_keys(config('soundex.output_formats')))],
        ];
    }
}
