<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePronunciationRequest;
use App\Models\PronunciationEntry;
use App\Services\HeyGen\HeyGenException;
use App\Services\HeyGen\HeyGenService;
use App\Services\PronunciationService;
use App\Services\SettingsService;
use App\Services\VoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PronunciationController extends Controller
{
    public function index(Request $request, VoiceService $voices): View
    {
        return view('settings.pronunciations', [
            'entries' => $request->user()->pronunciationEntries()->get(),
            'defaultVoice' => $voices->defaultVoice(),
        ]);
    }

    public function store(StorePronunciationRequest $request): RedirectResponse
    {
        $request->user()->pronunciationEntries()->create($request->validated() + ['is_active' => true]);

        return back()->with('success', '“'.$request->validated('term').'” added to your pronunciation dictionary.');
    }

    public function update(StorePronunciationRequest $request, PronunciationEntry $pronunciation): RedirectResponse
    {
        $this->authorize('update', $pronunciation);
        $pronunciation->update($request->validated() + ['is_active' => $request->boolean('is_active')]);

        return back()->with('success', 'Pronunciation updated.');
    }

    public function destroy(PronunciationEntry $pronunciation): RedirectResponse
    {
        $this->authorize('delete', $pronunciation);
        $pronunciation->delete();

        return back()->with('success', 'Pronunciation removed.');
    }

    /**
     * Speak a short sample with the default voice and the given pronunciation.
     * This is a real HeyGen request (it uses a few credits); the audio URL is HeyGen's pre-signed file.
     */
    public function preview(Request $request, HeyGenService $heygen, VoiceService $voices, PronunciationService $pronunciations, SettingsService $settings): JsonResponse
    {
        $data = $request->validate([
            'term' => ['required', 'string', 'max:128'],
            'ipa' => ['required', 'string', 'min:3', 'max:512', 'regex:/^\/[^\/]+\/$/u'],
        ]);

        $voice = $voices->defaultVoice();
        if (! $voice || ! $voice->isActive()) {
            return response()->json(['message' => 'Choose an active default voice in Voice Settings first.'], 422);
        }

        $model = (string) $settings->get('generation.model');
        if (! $pronunciations->isSupported($voice->mode, $model)) {
            $model = 'heygen-voice-1';
        }
        if (! $pronunciations->isSupported($voice->mode, $model)) {
            return response()->json(['message' => 'HeyGen applies pronunciation dictionaries to instant voice clones only.'], 422);
        }

        try {
            $result = $heygen->synthesize($voice->voice_id, $data['term'].'.', [
                'model' => $model,
                'output_format' => 'mp3',
                'mp3_bitrate' => 64000,
                'pronunciation_dictionary' => [$data['term'] => $data['ipa']],
            ]);
        } catch (HeyGenException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['audio_url' => $result->audioUrl]);
    }
}
