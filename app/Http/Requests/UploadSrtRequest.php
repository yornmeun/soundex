<?php

namespace App\Http\Requests;

use App\Services\SettingsService;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class UploadSrtRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $maxKb = (int) app(SettingsService::class)->get('upload.max_kb');

        return [
            'file' => [
                'required',
                'file',
                "max:{$maxKb}",
                // Browsers report SRT with many MIME types; validate the extension and that the content is text.
                'extensions:srt',
                'mimetypes:text/plain,application/x-subrip,text/x-subrip,application/x-srt,text/srt,text/x-srt,application/octet-stream',
                function (string $attribute, mixed $value, Closure $fail) {
                    $head = (string) @file_get_contents($value->getRealPath(), false, null, 0, 4096);
                    if ($head !== '' && str_contains($head, "\0") && ! str_starts_with($head, "\xFF\xFE") && ! str_starts_with($head, "\xFE\xFF")) {
                        $fail('The file does not look like a text subtitle file.');
                    }
                },
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'file.extensions' => 'Only .srt subtitle files are accepted.',
            'file.mimetypes' => 'The file type is not a valid SRT subtitle file.',
            'file.max' => 'The file is too large (maximum :max KB).',
        ];
    }
}
