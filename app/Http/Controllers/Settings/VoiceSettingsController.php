<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\AddVoiceRequest;
use App\Models\VoiceProfile;
use App\Services\Azure\AzureSpeechService;
use App\Services\HeyGen\HeyGenException;
use App\Services\HeyGen\HeyGenService;
use App\Services\SettingsService;
use App\Services\VoiceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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
            'hasAzure' => $settings->hasAzure(),
            'azureLocales' => (array) config('soundex.azure.voice_locales'),
            'targetName' => config('soundex.heygen.default_voice_name'),
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
            $redirect->with('warning', 'No voice named “'.config('soundex.heygen.default_voice_name').'” was found. Choose a default voice below, or add it by voice ID.');
        }

        return $redirect;
    }

    public function refreshAzure(): RedirectResponse
    {
        try {
            $result = $this->voices->syncAzure();
        } catch (HeyGenException $e) {
            return back()->with('error', 'Could not load Microsoft voices: '.$e->getMessage());
        }

        if ($result['total'] === 0) {
            return back()->with('warning', 'Microsoft returned no voices for '.implode(', ', (array) config('soundex.azure.voice_locales')).'. Check AZURE_SPEECH_VOICE_LOCALES.');
        }

        return back()->with('success', "Loaded {$result['total']} Microsoft voice(s) (".implode(', ', $result['locales']).').');
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

    /**
     * HeyGen: redirect to a fresh preview URL (they expire, so they are not stored).
     * Microsoft: speak a short sample once and keep it, so repeated previews are free.
     */
    public function preview(VoiceProfile $voice, HeyGenService $heygen, AzureSpeechService $azure): RedirectResponse|BinaryFileResponse
    {
        if ($voice->isAzure()) {
            $disk = Storage::disk(config('soundex.storage_disk'));
            $relative = 'voice-previews/'.preg_replace('/[^A-Za-z0-9_\-]/', '', $voice->voice_id).'.mp3';
            if (! $disk->exists($relative)) {
                try {
                    $azure->synthesizeTo($voice->voice_id, AzureSpeechService::previewText($voice->voice_id), $disk->path($relative), ['output_format' => 'mp3']);
                } catch (HeyGenException) {
                    abort(404, 'No preview available for this voice.');
                }
            }

            return response()->file($disk->path($relative), ['Content-Type' => 'audio/mpeg', 'Cache-Control' => 'private, max-age=86400']);
        }

        try {
            $url = $heygen->getVoice($voice->voice_id)['preview_audio_url'] ?? null;
        } catch (HeyGenException) {
            $url = null;
        }

        abort_unless(is_string($url) && str_starts_with($url, 'https://'), 404, 'No preview available for this voice.');

        return redirect()->away($url);
    }
}
