<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AddVoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['voice_id' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9_\-]+$/']];
    }
}
