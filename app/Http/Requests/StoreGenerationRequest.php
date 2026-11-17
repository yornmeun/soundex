<?php

namespace App\Http\Requests;

use App\Enums\GenerationMode;
use App\Services\Srt\SegmentBuilder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGenerationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'project_name' => ['required', 'string', 'max:150'],
            'uploaded_file_id' => ['nullable', 'integer', Rule::exists('uploaded_files', 'id')->where('user_id', $this->user()->id)],
            'mode' => ['required', Rule::enum(GenerationMode::class)],
            'script' => ['required_if:mode,continuous', 'nullable', 'string', 'max:500000'],
            'segment_strategy' => ['nullable', Rule::in([SegmentBuilder::STRATEGY_SENTENCE, SegmentBuilder::STRATEGY_SUBTITLE])],
            'segment_texts' => ['nullable', 'array', 'max:5000'],
            'segment_texts.*' => ['nullable', 'string', 'max:'.config('kikitoons.heygen.max_chars_per_request')],
            // Local voice_profiles.id - the HeyGen voice ID never travels through the browser.
            'voice_profile_id' => ['required', 'integer', Rule::exists('voice_profiles', 'id')],
            'language' => ['required', Rule::in(['auto', ...array_keys(config('kikitoons.languages'))])],
            'model' => ['required', Rule::in(array_keys(config('kikitoons.models')))],
            'output_format' => ['required', Rule::in(array_keys(config('kikitoons.output_formats')))],
            'mp3_bitrate' => ['required_if:output_format,mp3', 'nullable', 'integer', Rule::in(config('kikitoons.mp3_bitrates'))],
            'speed' => ['nullable', 'numeric', 'between:0.75,1.25'],
            'silence_between_ms' => ['nullable', 'integer', 'between:0,5000'],
            'normalize_loudness' => ['nullable', 'boolean'],
            'trim_chunk_silence' => ['nullable', 'boolean'],
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
