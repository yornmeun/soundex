<?php

namespace App\Http\Requests;

use App\Models\AudioAsset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAudioAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in([AudioAsset::TYPE_MUSIC, AudioAsset::TYPE_SFX])],
            'name' => ['nullable', 'string', 'max:150'],
            'file' => ['required', 'file', 'max:30720', 'extensions:mp3,wav,ogg,m4a,aac,flac',
                'mimetypes:audio/mpeg,audio/mp3,audio/wav,audio/x-wav,audio/wave,audio/ogg,audio/x-m4a,audio/mp4,audio/aac,audio/flac,audio/x-flac,video/mp4,application/ogg,application/octet-stream'],
        ];
    }

    public function messages(): array
    {
        return ['file.extensions' => 'Upload an MP3, WAV, OGG, M4A, AAC or FLAC file.', 'file.max' => 'Sound files can be at most 30 MB.'];
    }
}
