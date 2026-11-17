<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateApiSettingsRequest;
use App\Services\Audio\AudioProcessor;
use App\Services\HeyGen\HeyGenException;
use App\Services\HeyGen\HeyGenService;
use App\Services\SettingsService;
use App\Support\WorkerHeartbeat;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ApiSettingsController extends Controller
{
    public function __construct(private readonly SettingsService $settings) {}

    public function edit(AudioProcessor $audio): View
    {
        return view('settings.api', [
            'maskedKey' => $this->settings->maskedApiKey(),
            'keySource' => $this->settings->apiKeySource(),
            'maskedVoiceId' => SettingsService::mask($this->settings->defaultVoiceId()),
            'defaultLanguage' => $this->settings->get('generation.language') ?? 'auto',
            'defaultOutputFormat' => $this->settings->get('generation.output_format'),
            'ffmpegVersion' => $audio->version(),
            'workerOnline' => WorkerHeartbeat::isOnline(),
        ]);
    }

    public function update(UpdateApiSettingsRequest $request): RedirectResponse
    {
        $data = $request->validated();

        if ($request->boolean('remove_api_key')) {
            $this->settings->setApiKey(null);
        } elseif (filled($data['api_key'] ?? null)) {
            $this->settings->setApiKey($data['api_key']);
        }

        if (filled($data['default_voice_id'] ?? null)) {
            $this->settings->setDefaultVoiceId($data['default_voice_id']);
        }

        $this->settings->set('generation.language', $data['default_language']);
        $this->settings->set('generation.output_format', $data['default_output_format']);

        return back()->with('success', 'API settings saved.');
    }

    public function test(HeyGenService $heygen): RedirectResponse
    {
        try {
            $info = $heygen->testConnection();
        } catch (HeyGenException $e) {
            return back()->with('error', 'Connection failed: '.$e->getMessage());
        }

        $who = $info['email'] ?? $info['username'] ?? 'your account';

        return back()->with('success', "Connected to HeyGen as {$who}".($info['billing_type'] ? " ({$info['billing_type']} billing)" : '').'.');
    }
}
