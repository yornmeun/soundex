<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\GenerationSettingsRules;
use App\Services\Srt\SegmentBuilder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBatchRequest extends FormRequest
{
    use GenerationSettingsRules;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            ...$this->settingsRules(),
            'name' => ['required', 'string', 'max:150'],
            'mode' => ['required', Rule::in(['continuous', 'segments'])],
            'segment_strategy' => ['nullable', Rule::in([SegmentBuilder::STRATEGY_SENTENCE, SegmentBuilder::STRATEGY_SUBTITLE])],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.uploaded_file_id' => ['required', 'integer', 'distinct', Rule::exists('uploaded_files', 'id')->where('user_id', $this->user()->id)],
            'items.*.project_name' => ['required', 'string', 'max:150'],
        ];
    }

    public function messages(): array
    {
        return ['items.required' => 'Add at least one SRT file.', 'items.max' => 'A batch can contain at most 50 files.'];
    }
}
