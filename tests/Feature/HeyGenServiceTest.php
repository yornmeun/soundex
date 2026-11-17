<?php

namespace Tests\Feature;

use App\Services\HeyGen\HeyGenException;
use App\Services\HeyGen\HeyGenService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsGenerations;
use Tests\TestCase;

class HeyGenServiceTest extends TestCase
{
    use BuildsGenerations, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureHeyGen();
    }

    public function test_synthesize_sends_documented_payload_and_parses_response(): void
    {
        $this->fakeHeyGenSuccess();

        $result = app(HeyGenService::class)->synthesize('voice_kikitoons_123', 'Hello Kiki.', [
            'model' => 'heygen-voice-1', 'language' => 'en', 'output_format' => 'mp3', 'mp3_bitrate' => 192000,
        ]);

        $this->assertSame('https://files.heygen.test/audio/abc.mp3', $result->audioUrl);
        $this->assertSame(1.4, $result->durationSeconds);
        $this->assertSame('req_123', $result->requestId);

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://heygen.test/v3/models/audio/tts'
                && $request->method() === 'POST'
                && $request->hasHeader('X-Api-Key', 'test-heygen-key-1234567890')
                && $request['voice_id'] === 'voice_kikitoons_123'
                && $request['text'] === 'Hello Kiki.'
                && $request['language'] === 'en'
                && $request['output_format'] === ['encoding' => 'mp3', 'sample_rate_hertz' => 44100, 'bitrate_bps' => 192000]
                && ! isset($request['speed']);
        });
    }

    public function test_wav_output_omits_output_format_and_auto_language_is_omitted(): void
    {
        $this->fakeHeyGenSuccess();

        app(HeyGenService::class)->synthesize('v1', 'Hi.', ['output_format' => 'wav', 'language' => null]);

        Http::assertSent(fn (Request $r) => ! isset($r['output_format']) && ! isset($r['language']));
    }

    public function test_rate_limit_error_is_retryable_and_exposes_retry_after(): void
    {
        Http::fake(['heygen.test/*' => Http::response(['error' => ['code' => 'rate_limit_exceeded', 'message' => 'Too many']], 429, ['Retry-After' => '7'])]);

        try {
            app(HeyGenService::class)->synthesize('v1', 'Hi.');
            $this->fail('Expected exception');
        } catch (HeyGenException $e) {
            $this->assertSame(HeyGenException::CATEGORY_RATE_LIMIT, $e->category);
            $this->assertTrue($e->isRetryable());
            $this->assertSame(7, $e->retryAfterSeconds);
        }
    }

    public function test_quota_and_billing_errors_are_not_retryable(): void
    {
        Http::fake(['heygen.test/*' => Http::sequence()
            ->push(['error' => ['code' => 'usage_limit_reached', 'message' => 'Limit']], 402)
            ->push(['error' => ['code' => 'insufficient_credit', 'message' => 'Credits']], 402)]);

        foreach (['usage_limit_reached', 'insufficient_credit'] as $code) {
            try {
                app(HeyGenService::class)->synthesize('v1', 'Hi.');
                $this->fail('Expected exception');
            } catch (HeyGenException $e) {
                $this->assertSame(HeyGenException::CATEGORY_QUOTA, $e->category);
                $this->assertFalse($e->isRetryable());
                $this->assertSame($code, $e->errorCode);
            }
        }
    }

    public function test_server_errors_are_transient_and_auth_errors_are_not(): void
    {
        Http::fake(['heygen.test/*' => Http::sequence()
            ->push(['error' => ['code' => 'service_unavailable', 'message' => 'Busy']], 503)
            ->push(['error' => ['code' => 'unauthorized', 'message' => 'Bad key']], 401)]);

        $service = app(HeyGenService::class);
        try {
            $service->synthesize('v1', 'Hi.');
        } catch (HeyGenException $e) {
            $this->assertTrue($e->isRetryable());
        }
        try {
            $service->synthesize('v1', 'Hi.');
        } catch (HeyGenException $e) {
            $this->assertSame(HeyGenException::CATEGORY_AUTH, $e->category);
            $this->assertFalse($e->isRetryable());
        }
    }

    public function test_text_over_the_documented_limit_is_rejected_without_calling_the_api(): void
    {
        Http::fake();

        $this->expectException(HeyGenException::class);
        try {
            app(HeyGenService::class)->synthesize('v1', str_repeat('a', 10001));
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_missing_api_key_throws_config_error(): void
    {
        app(SettingsService::class)->setApiKey(null);
        Http::fake();

        $this->expectException(HeyGenException::class);
        $this->expectExceptionMessage('API key is not configured');
        app(HeyGenService::class)->synthesize('v1', 'Hi.');
    }

    public function test_list_private_voices_follows_pagination(): void
    {
        Http::fake(['heygen.test/v3/models/audio/voices*' => Http::sequence()
            ->push(['data' => [['voice_id' => 'a', 'name' => 'kikitoons', 'mode' => 'instant', 'status' => 'ACTIVE', 'created_at' => 1700000000]], 'has_more' => true, 'next_token' => 'tok2'])
            ->push(['data' => [['voice_id' => 'b', 'name' => 'Other', 'mode' => 'professional', 'status' => 'PENDING', 'created_at' => 1700000001]], 'has_more' => false, 'next_token' => null])]);

        $voices = app(HeyGenService::class)->listPrivateVoices();

        $this->assertSame(['a', 'b'], array_column($voices, 'voice_id'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'type=private') && str_contains($r->url(), 'token=tok2'));
    }

    public function test_api_key_never_appears_in_logs(): void
    {
        $logFile = storage_path('logs/laravel.log');
        $before = is_file($logFile) ? filesize($logFile) : 0;
        Http::fake(['heygen.test/*' => Http::response(['error' => ['code' => 'unauthorized', 'message' => 'Bad']], 401)]);

        try {
            app(HeyGenService::class)->testConnection();
        } catch (HeyGenException) {
        }

        $after = is_file($logFile) ? (string) file_get_contents($logFile, false, null, $before) : '';
        $this->assertStringNotContainsString('test-heygen-key-1234567890', $after);
    }
}
