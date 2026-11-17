<?php

namespace App\Http\Requests;

use App\Models\PronunciationEntry;
use Illuminate\Foundation\Http\FormRequest;

class StorePronunciationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $ipa = trim((string) $this->input('ipa'));
        // Accept IPA typed without the surrounding slashes.
        if ($ipa !== '' && ! (str_starts_with($ipa, '/') && str_ends_with($ipa, '/') && mb_strlen($ipa) > 1)) {
            $ipa = '/'.trim($ipa, '/').'/';
        }
        $this->merge(['term' => trim((string) $this->input('term')), 'ipa' => $ipa]);
    }

    public function rules(): array
    {
        $entry = $this->route('pronunciation');

        return [
            // Limits documented for HeyGen's pronunciation_dictionary.
            'term' => ['required', 'string', 'min:1', 'max:128', function (string $attribute, mixed $value, \Closure $fail) use ($entry) {
                // HeyGen matches terms case-insensitively, so "Kiki" and "kiki" are the same entry.
                $exists = PronunciationEntry::query()
                    ->where('user_id', $this->user()->id)
                    ->whereRaw('LOWER(term) = ?', [mb_strtolower((string) $value)])
                    ->when($entry, fn ($q) => $q->whereKeyNot($entry->id))
                    ->exists();
                if ($exists) {
                    $fail('This term is already in your dictionary.');
                }
            }],
            'ipa' => ['required', 'string', 'min:3', 'max:512', 'regex:/^\/[^\/]+\/$/u'],
            'note' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return ['ipa.regex' => 'Write the pronunciation in IPA between slashes, for example /ˈkiːki/.'];
    }
}
