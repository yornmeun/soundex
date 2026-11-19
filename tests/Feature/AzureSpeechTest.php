<?php

namespace Tests\Feature;

use App\Enums\GenerationStatus;
use App\Models\User;
use App\Models\VoiceProfile;
use App\Services\Azure\AzureSpeechService;
use App\Services\Generation\GenerationProcessor;
use App\Services\HeyGen\HeyGenException;
use App\Services\SettingsService;
use App\Services\VoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\Support\BuildsGenerations;
use Tests\TestCase;

/** Microsoft Azure Speech as a second text-to-speech provider (Khmer support). */
class AzureSpeechTest extends TestCase
{
    use BuildsGenerations, RefreshDatabase;

    private const VOICES_URL = 'southeastasia.tts.speech.microsoft.com/cognitiveservices/voices/list';

    private const TTS_URL = 'southeastasia.tts.speech.microsoft.com/cognitiveservices/v1';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Sleep::fake();
        $settings = app(SettingsService::class);
        $settings->setAzureKey('azure-test-key-1234567890');
        $settings->setAzureRegion('Southeast Asia');
    }

    private function voiceList(): array
    {
        return [
            ['ShortName' => 'km-KH-SreymomNeural', 'DisplayName' => 'Sreymom', 'LocaleName' => 'Khmer (Cambodia)', 'Locale' => 'km-KH', 'Gender' => 'Female', 'Status' => 'GA'],
            ['ShortName' => 'km-KH-PisethNeural', 'DisplayName' => 'Piseth', 'LocaleName' => 'Khmer (Cambodia)', 'Locale' => 'km-KH', 'Gender' => 'Male', 'Status' => 'GA'],
            ['ShortName' => 'en-US-AvaNeural', 'DisplayName' => 'Ava', 'LocaleName' => 'English (United States)', 'Locale' => 'en-US', 'Gender' => 'Female', 'Status' => 'GA'],
            ['ShortName' => 'fr-FR-DeniseNeural', 'DisplayName' => 'Denise', 'LocaleName' => 'French (France)', 'Locale' => 'fr-FR', 'Gender' => 'Female', 'Status' => 'GA'],
            ['ShortName' => 'km-KH-OldNeural', 'DisplayName' => 'Old', 'LocaleName' => 'Khmer (Cambodia)', 'Locale' => 'km-KH', 'Gender' => 'Male', 'Status' => 'Deprecated'],
        ];
    }

    public function test_region_is_normalised_and_voices_are_filtered_by_locale(): void
    {
        Http::fake([self::VOICES_URL => Http::response($this->voiceList())]);

        $this->assertSame('southeastasia', app(SettingsService::class)->azureRegion());
        $voices = app(AzureSpeechService::class)->listVoices(['km-KH']);

        $this->assertSame(['km-KH-SreymomNeural', 'km-KH-PisethNeural'], array_column($voices, 'voice_id'));
        $this->assertSame('Sreymom · Khmer (Cambodia)', $voices[0]['name']);
        Http::assertSent(fn (Request $r) => $r->hasHeader('Ocp-Apim-Subscription-Key', 'azure-test-key-1234567890'));
    }

    public function test_khmer_text_is_sent_as_escaped_ssml_and_audio_is_saved(): void
    {
        Http::fake([self::TTS_URL => Http::response('ID3-azure-audio', 200, ['X-RequestId' => 'az_1'])]);
        $path = Storage::disk('local')->path('t/out.mp3');

        $result = app(AzureSpeechService::class)->synthesizeTo('km-KH-SreymomNeural', "សួស្តី! ខ្ញុំឈ្មោះគីគី។ <Tom & Jerry>\n\nថ្ងៃនេះ", $path, ['output_format' => 'mp3', 'speed' => 1.1]);

        $this->assertSame('ID3-azure-audio', file_get_contents($path));
        $this->assertSame('az_1', $result->requestId);
        Http::assertSent(function (Request $r) {
            $body = $r->body();

            return $r->hasHeader('X-Microsoft-OutputFormat', 'audio-48khz-192kbitrate-mono-mp3')
                && str_contains($r->header('Content-Type')[0] ?? '', 'application/ssml+xml')
                && str_contains($body, 'xml:lang="km-KH"')
                && str_contains($body, '<voice name="km-KH-SreymomNeural">')
                && str_contains($body, 'សួស្តី! ខ្ញុំឈ្មោះគីគី។')
                && str_contains($body, '&lt;Tom &amp; Jerry&gt;')
                && str_contains($body, '<break time="600ms"/>')
                && str_contains($body, '<prosody rate="+10%">')
                && simplexml_load_string($body) !== false;
        });
    }

    public function test_errors_map_to_retryable_and_permanent_categories(): void
    {
        Http::fake([self::TTS_URL => Http::sequence()->push('Too many requests', 429)->push('', 401)]);
        $azure = app(AzureSpeechService::class);
        $path = Storage::disk('local')->path('t/x.mp3');

        try {
            $azure->synthesizeTo('km-KH-SreymomNeural', 'សួស្តី', $path);
            $this->fail('Expected an exception');
        } catch (HeyGenException $e) {
            $this->assertTrue($e->isRetryable());
        }

        try {
            $azure->synthesizeTo('km-KH-SreymomNeural', 'សួស្តី', $path);
            $this->fail('Expected an exception');
        } catch (HeyGenException $e) {
            $this->assertSame(HeyGenException::CATEGORY_AUTH, $e->category);
            $this->assertFalse($e->isRetryable());
        }
    }

    public function test_microsoft_voices_sync_without_touching_heygen_voices(): void
    {
        config(['soundex.azure.voice_locales' => ['km-KH', 'en-US']]);
        Http::fake([self::VOICES_URL => Http::response($this->voiceList())]);
        $this->kikitoonsVoice();

        $result = app(VoiceService::class)->syncAzure();

        $this->assertSame(3, $result['total']);
        $sreymom = VoiceProfile::query()->where('voice_id', 'km-KH-SreymomNeural')->first();
        $this->assertTrue($sreymom->isAzure() && $sreymom->isActive());
        $this->assertSame('km-KH', $sreymom->language);
        $this->assertTrue(VoiceProfile::query()->where('voice_id', 'voice_kikitoons_123')->first()->isActive());

        // A HeyGen refresh must not mark Microsoft voices as missing.
        app(SettingsService::class)->setApiKey('test-heygen-key-1234567890');
        Http::fake(['heygen.test/v3/models/audio/voices*' => Http::response(['data' => [['voice_id' => 'voice_kikitoons_123', 'name' => 'kikitoons', 'status' => 'ACTIVE']], 'has_more' => false])]);
        app(VoiceService::class)->sync();
        $this->assertTrue($sreymom->refresh()->isActive());
    }

    public function test_khmer_generation_with_a_microsoft_voice_needs_no_heygen_key(): void
    {
        $this->useFakeAudio(available: false); // basic mode, no FFmpeg
        Http::fake([self::TTS_URL => fn () => Http::response('ID3-azure-audio', 200)]);
        $generation = $this->makeGeneration(attrs: [
            'script' => 'សួស្តី! ខ្ញុំឈ្មោះគីគី។ ថ្ងៃនេះយើងនឹងរៀនអំពីព្រៃឈើ។',
            'voice_id' => 'km-KH-PisethNeural',
            'voice_name' => 'Piseth · Khmer (Cambodia)',
            'voice_mode' => AzureSpeechService::MODE,
            'language' => 'auto',
            'speed' => 0.9,
        ]);

        app(GenerationProcessor::class)->process($generation->id);

        $generation->refresh();
        $this->assertSame(GenerationStatus::Completed, $generation->status, (string) $generation->error_message);
        $this->assertSame(1, $generation->total_chunks);
        Http::assertSent(fn (Request $r) => str_contains($r->body(), 'km-KH-PisethNeural') && str_contains($r->body(), 'ថ្ងៃនេះយើងនឹងរៀនអំពីព្រៃឈើ។') && str_contains($r->body(), 'rate="-10%"'));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'heygen'));
        Storage::disk('local')->assertExists($generation->chunks()->first()->audio_path);
        $this->assertFalse($generation->hasWordTimings(), 'Azure REST gives no word timings.');
    }

    public function test_generation_with_a_microsoft_voice_fails_fast_when_not_configured(): void
    {
        app(SettingsService::class)->setAzureKey(null);
        config(['soundex.azure.key' => null]);
        $this->useFakeAudio();
        Http::fake();
        $generation = $this->makeGeneration(attrs: ['voice_id' => 'km-KH-PisethNeural', 'voice_mode' => AzureSpeechService::MODE]);

        app(GenerationProcessor::class)->process($generation->id);

        $this->assertSame(GenerationStatus::Failed, $generation->refresh()->status);
        $this->assertStringContainsString('Microsoft Speech is not configured', $generation->error_message);
        Http::assertNothingSent();
    }

    public function test_settings_pages_save_test_and_load_microsoft_voices(): void
    {
        $user = User::factory()->create();
        Http::fake([self::VOICES_URL => Http::response($this->voiceList())]);

        $this->actingAs($user)->put(route('settings.api.update'), [
            'azure_key' => 'another-azure-key-123456', 'azure_region' => 'eastus',
            'default_language' => 'auto', 'default_output_format' => 'mp3',
        ])->assertSessionHasNoErrors();
        $this->assertSame('another-azure-key-123456', app(SettingsService::class)->azureKey());
        $this->assertSame('eastus', app(SettingsService::class)->azureRegion());

        $this->actingAs($user)->get(route('settings.api'))->assertOk()->assertSee('Microsoft Azure Speech')->assertDontSee('another-azure-key-123456');

        Http::fake(['eastus.tts.speech.microsoft.com/*' => Http::response($this->voiceList())]);
        $this->actingAs($user)->post(route('settings.api.test-azure'))->assertSessionHas('success');
        $this->actingAs($user)->post(route('settings.voices.refresh-azure'))->assertSessionHas('success');
        $this->assertTrue(VoiceProfile::query()->azure()->where('voice_id', 'km-KH-SreymomNeural')->exists());

        $this->actingAs($user)->get(route('settings.voices'))->assertOk()->assertSee('Sreymom · Khmer (Cambodia)')->assertSee('Microsoft voices');
        $this->actingAs($user)->get(route('generations.create'))->assertOk()->assertDontSee('No speech provider is connected');
    }

    public function test_microsoft_voice_preview_is_generated_once_and_cached(): void
    {
        Http::fake([self::TTS_URL => fn () => Http::response('ID3-preview', 200)]);
        $voice = VoiceProfile::query()->create(['voice_id' => 'km-KH-SreymomNeural', 'name' => 'Sreymom', 'mode' => 'azure', 'language' => 'km-KH', 'status' => 'ACTIVE', 'is_available' => true]);
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('settings.voices.preview', $voice))->assertOk();
        $this->actingAs($user)->get(route('settings.voices.preview', $voice))->assertOk();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => str_contains($r->body(), 'សួស្តី'));
    }
}
