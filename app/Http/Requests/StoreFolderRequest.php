<?php

namespace App\Http\Requests;

use App\Models\Folder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFolderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $folder = $this->route('folder');

        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('folders', 'name')->where('user_id', $this->user()->id)->ignore($folder?->id)],
            'description' => ['nullable', 'string', 'max:500'],
            'color' => ['nullable', Rule::in(Folder::COLORS)],
        ];
    }
}
