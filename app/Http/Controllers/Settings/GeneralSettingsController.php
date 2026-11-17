<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateGeneralSettingsRequest;
use App\Services\SettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class GeneralSettingsController extends Controller
{
    public function __construct(private readonly SettingsService $settings) {}

    public function edit(): View
    {
        return view('settings.general', ['values' => $this->settings->generationDefaults()]);
    }

    public function update(UpdateGeneralSettingsRequest $request): RedirectResponse
    {
        $d = $request->validated();

        $this->settings->set('generation.model', $d['model']);
        $this->settings->set('generation.mp3_bitrate', (int) $d['mp3_bitrate']);
        $this->settings->set('generation.chunk_max_chars', (int) $d['chunk_max_chars']);
        $this->settings->set('generation.silence_between_ms', (int) $d['silence_between_ms']);
        $this->settings->set('generation.normalize_loudness', $request->boolean('normalize_loudness'));
        $this->settings->set('generation.trim_chunk_silence', $request->boolean('trim_chunk_silence'));
        $this->settings->set('upload.max_kb', (int) $d['max_upload_kb']);

        return back()->with('success', 'General settings saved.');
    }
}
