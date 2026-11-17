<?php

namespace App\Http\Requests;

use App\Enums\GenerationMode;
use App\Http\Requests\Concerns\GenerationSettingsRules;
use App\Services\Srt\SegmentBuilder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGenerationRequest extends FormRequest
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
            'project_name' => ['required', 'string', 'max:150'],
            'uploaded_file_id' => ['nullable', 'integer', Rule::exists('uploaded_files', 'id')->where('user_id', $this->user()->id)],
            'mode' => ['required', Rule::enum(GenerationMode::class)],
            'script' => ['required_if:mode,continuous', 'nullable', 'string', 'max:500000'],
            'segment_strategy' => ['nullable', Rule::in([SegmentBuilder::STRATEGY_SENTENCE, SegmentBuilder::STRATEGY_SUBTITLE])],
            'segment_texts' => ['nullable', 'array', 'max:5000'],
            'segment_texts.*' => ['nullable', 'string', 'max:'.config('soundex.heygen.max_chars_per_request')],
        ];
    }

    public function messages(): array
    {
        return [
            'script.required_if' => 'The narration script is empty.',
            'voice_profile_id.required' => 'Please choose a voice.',
        ];
    }
}
