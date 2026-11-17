<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\AddVoiceRequest;
use App\Models\VoiceProfile;
use App\Services\HeyGen\HeyGenException;
use App\Services\HeyGen\HeyGenService;
use App\Services\SettingsService;
use App\Services\VoiceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class VoiceSettingsController extends Controller
{
    public function __construct(private readonly VoiceService $voices) {}

    public function index(SettingsService $settings): View
    {
        return view('settings.voices', [
            'voices' => VoiceProfile::query()->orderByDesc('is_default')->orderByDesc('is_available')->orderBy('name')->get(),
            'defaultVoice' => $this->voices->defaultVoice(),
            'defaultVoiceIdSet' => filled($settings->defaultVoiceId()),
            'hasApiKey' => filled($settings->apiKey()),
            'targetName' => config('kikitoons.heygen.default_voice_name'),
        ]);
    }

    public function refresh(): RedirectResponse
    {
        try {
            $result = $this->voices->sync();
        } catch (HeyGenException $e) {
            return back()->with('error', 'Could not load voices from HeyGen: '.$e->getMessage());
        }

        $message = "Loaded {$result['total']} voice(s) from HeyGen ({$result['active']} active).";
        if ($result['auto_selected']) {
            $message .= " Selected your existing “{$result['default_voice_name']}” voice as the default.";
        }

        $redirect = back()->with('success', $message);

        if ($result['default_missing']) {
            $redirect->with('error', 'Your default voice is no longer active in HeyGen. It was NOT replaced automatically; please choose a voice.');
        } elseif ($result['total'] > 0 && ! $result['default_voice_name']) {
            $redirect->with('warning', 'No voice named “'.config('kikitoons.heygen.default_voice_name').'” was found. Choose a default voice below, or add it by voice ID.');
        }

        return $redirect;
    }

    public function setDefault(VoiceProfile $voice): RedirectResponse
    {
        if (! $voice->isActive()) {
            return back()->with('error', 'Only ACTIVE voices can be the default.');
        }

        $this->voices->setDefault($voice);

        return back()->with('success', "“{$voice->name}” is now the default voice.");
    }

    public function add(AddVoiceRequest $request): RedirectResponse
    {
        try {
            $voice = $this->voices->addById($request->validated('voice_id'));
        } catch (HeyGenException $e) {
            return back()->with('error', 'HeyGen could not find that voice in your workspace: '.$e->getMessage());
        }

        return back()->with('success', "Voice “{$voice->name}” added ({$voice->status}).");
    }

    /** Redirect to a fresh preview URL (HeyGen preview URLs expire, so they are not stored). */
    public function preview(VoiceProfile $voice, HeyGenService $heygen): RedirectResponse
    {
        try {
            $url = $heygen->getVoice($voice->voice_id)['preview_audio_url'] ?? null;
        } catch (HeyGenException) {
            $url = null;
        }

        abort_unless(is_string($url) && str_starts_with($url, 'https://'), 404, 'No preview available for this voice.');

        return redirect()->away($url);
    }
}
