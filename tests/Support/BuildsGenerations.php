<?php

namespace Tests\Support;

use App\Enums\GenerationMode;
use App\Enums\GenerationStage;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use App\Models\VoiceProfile;
use App\Services\Audio\AudioProcessor;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Http;

trait BuildsGenerations
{
    protected FakeAudioProcessor $audio;

    protected function useFakeAudio(bool $available = true): FakeAudioProcessor
    {
        $this->audio = new FakeAudioProcessor($available);
        $this->app->instance(AudioProcessor::class, $this->audio);

        return $this->audio;
    }

    protected function configureHeyGen(): void
    {
        app(SettingsService::class)->setApiKey('test-heygen-key-1234567890');
    }

    protected function kikitoonsVoice(array $attrs = []): VoiceProfile
    {
        return VoiceProfile::query()->create(array_merge([
            'voice_id' => 'voice_kikitoons_123',
            'name' => 'kikitoons',
            'mode' => 'instant',
            'language' => 'en',
            'status' => 'ACTIVE',
            'is_available' => true,
        ], $attrs));
    }

    protected function makeGeneration(?User $user = null, array $attrs = []): Generation
    {
        $user ??= User::factory()->create();

        return Generation::query()->create(array_merge([
            'user_id' => $user->id,
            'project_name' => 'Kiki story',
            'original_filename' => 'story.srt',
            'script' => 'First sentence here. Second sentence here. Third sentence here.',
            'mode' => GenerationMode::Continuous,
            'voice_id' => 'voice_kikitoons_123',
            'voice_name' => 'kikitoons',
            'voice_mode' => 'instant',
            'language' => 'en',
            'model' => 'heygen-voice-1',
            'output_format' => 'mp3',
            'mp3_bitrate' => 128000,
            'silence_between_ms' => 300,
            'normalize_loudness' => true,
            'total_words' => 9,
            'status' => GenerationStatus::Pending,
            'stage' => GenerationStage::Queued,
        ], $attrs));
    }

    /** Successful TTS + audio download responses. */
    protected function fakeHeyGenSuccess(): void
    {
        Http::fake([
            // Closures build a fresh response (and body stream) for every request.
            'heygen.test/v3/models/audio/tts' => fn () => Http::response(
                ['data' => ['audio_url' => 'https://files.heygen.test/audio/abc.mp3', 'duration' => 1.4]],
                200, ['X-Request-Id' => 'req_123'],
            ),
            'files.heygen.test/*' => fn () => Http::response('ID3-fake-mp3-bytes', 200, ['Content-Type' => 'audio/mpeg']),
        ]);
    }

    protected function ttsRequestCount(): int
    {
        return count(Http::recorded(fn ($request) => str_contains($request->url(), '/v3/models/audio/tts')));
    }
}
