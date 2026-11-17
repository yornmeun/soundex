<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Models\VoiceProfile;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsGenerations;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use BuildsGenerations, RefreshDatabase;

    private const KEY = 'sk_live_super_secret_heygen_key_ABCDEF';

    public function test_api_key_is_encrypted_at_rest_and_only_shown_masked(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put(route('settings.api.update'), [
            'api_key' => self::KEY, 'default_language' => 'en', 'default_output_format' => 'mp3',
        ])->assertRedirect();

        $row = Setting::query()->where('key', SettingsService::API_KEY)->firstOrFail();
        $this->assertTrue($row->is_encrypted);
        $this->assertStringNotContainsString(self::KEY, $row->getRawOriginal('value'));
        $this->assertSame(self::KEY, app(SettingsService::class)->apiKey());

        $page = $this->actingAs($user)->get(route('settings.api'))->assertOk();
        $page->assertDontSee(self::KEY);
        $page->assertSee('sk_l');
        $page->assertSee('CDEF');
    }

    public function test_blank_api_key_keeps_the_existing_key(): void
    {
        app(SettingsService::class)->setApiKey(self::KEY);

        $this->actingAs(User::factory()->create())->put(route('settings.api.update'), [
            'api_key' => '', 'default_language' => 'auto', 'default_output_format' => 'wav',
        ]);

        $this->assertSame(self::KEY, app(SettingsService::class)->apiKey());
        $this->assertSame('auto', app(SettingsService::class)->get('generation.language'));
    }

    public function test_connection_test_calls_users_me(): void
    {
        app(SettingsService::class)->setApiKey(self::KEY);
        Http::fake(['heygen.test/v3/users/me' => Http::response(['data' => ['username' => 'kiki', 'email' => 'kiki@example.com', 'first_name' => null, 'last_name' => null, 'billing_type' => 'wallet']])]);

        $this->actingAs(User::factory()->create())->post(route('settings.api.test'))
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'kiki@example.com'));
        Http::assertSent(fn (Request $r) => $r->hasHeader('X-Api-Key', self::KEY));
    }

    public function test_voice_sync_selects_existing_soundex_once_and_never_creates_voices(): void
    {
        app(SettingsService::class)->setApiKey(self::KEY);
        Http::fake(['heygen.test/v3/models/audio/voices*' => Http::response(['data' => [
            ['voice_id' => 'v_other', 'mode' => 'instant', 'name' => 'Narrator', 'status' => 'ACTIVE', 'created_at' => 1700000000],
            ['voice_id' => 'v_kiki', 'mode' => 'instant', 'name' => 'Kikitoons', 'language' => 'en', 'status' => 'ACTIVE', 'created_at' => 1700000000],
        ], 'has_more' => false])]);
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('settings.voices.refresh'))->assertSessionHas('success');

        $this->assertSame('v_kiki', app(SettingsService::class)->defaultVoiceId());
        $this->assertTrue(VoiceProfile::query()->where('voice_id', 'v_kiki')->value('is_default'));
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');

        // The user picks another voice; a later refresh must not switch it back.
        $this->actingAs($user)->post(route('settings.voices.default', VoiceProfile::query()->where('voice_id', 'v_other')->first()));
        $this->actingAs($user)->post(route('settings.voices.refresh'));
        $this->assertSame('v_other', app(SettingsService::class)->defaultVoiceId());
    }

    public function test_missing_default_voice_is_reported_not_replaced(): void
    {
        app(SettingsService::class)->setApiKey(self::KEY);
        app(SettingsService::class)->setDefaultVoiceId('v_gone');
        Http::fake(['heygen.test/v3/models/audio/voices*' => Http::response(['data' => [
            ['voice_id' => 'v_kiki', 'mode' => 'instant', 'name' => 'kikitoons', 'status' => 'ACTIVE', 'created_at' => 1700000000],
        ], 'has_more' => false])]);

        $this->actingAs(User::factory()->create())->post(route('settings.voices.refresh'))->assertSessionHas('error');

        $this->assertSame('v_gone', app(SettingsService::class)->defaultVoiceId());
    }

    public function test_general_settings_are_validated_and_saved(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put(route('settings.general.update'), [
            'model' => 'heygen-voice-1', 'mp3_bitrate' => 192000, 'chunk_max_chars' => 20000,
            'silence_between_ms' => 100, 'max_upload_kb' => 1024,
        ])->assertSessionHasErrors('chunk_max_chars');

        $this->actingAs($user)->put(route('settings.general.update'), [
            'model' => 'heygen-voice-1', 'mp3_bitrate' => 192000, 'chunk_max_chars' => 4000,
            'silence_between_ms' => 100, 'normalize_loudness' => '0', 'trim_chunk_silence' => '1', 'max_upload_kb' => 1024,
        ])->assertSessionHasNoErrors();

        $settings = app(SettingsService::class);
        $this->assertSame(4000, $settings->get('generation.chunk_max_chars'));
        $this->assertFalse($settings->get('generation.normalize_loudness'));
        $this->assertTrue($settings->get('generation.trim_chunk_silence'));
    }
}
