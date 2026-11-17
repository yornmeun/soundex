<?php

namespace Tests\Feature;

use App\Enums\GenerationStatus;
use App\Jobs\ProcessGeneration;
use App\Models\AudioAsset;
use App\Models\Folder;
use App\Models\Generation;
use App\Models\GenerationBatch;
use App\Models\PronunciationEntry;
use App\Models\User;
use App\Models\VoiceProfile;
use App\Services\Generation\GenerationProcessor;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\Support\BuildsGenerations;
use Tests\TestCase;

class UpgradeFeaturesTest extends TestCase
{
    use BuildsGenerations, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Sleep::fake();
        $this->useFakeAudio();
        $this->configureHeyGen();
    }

    private function ttsRequests()
    {
        return collect(Http::recorded())->map(fn ($p) => $p[0])->filter(fn ($r) => str_contains($r->url(), '/v3/models/audio/tts'))->values();
    }

    private function fakeTtsWithWords(): void
    {
        Http::fake([
            'heygen.test/v3/models/audio/tts' => fn (Request $r) => Http::response(['data' => [
                'audio_url' => 'https://files.heygen.test/a.mp3',
                'duration' => 1.2,
                'original_words' => array_map(
                    fn ($i, $w) => ['text' => $w, 'start_time' => $i * 0.4, 'end_time' => $i * 0.4 + 0.35],
                    array_keys(explode(' ', $r['text'])), explode(' ', $r['text']),
                ),
            ]], 200, ['X-Request-Id' => 'req']),
            'files.heygen.test/*' => fn () => Http::response('mp3'),
        ]);
    }

    // ---------------------------------------------------------------- Pronunciation

    public function test_pronunciations_are_sent_only_for_terms_in_each_chunk_with_word_timestamps(): void
    {
        $this->fakeTtsWithWords();
        $user = User::factory()->create();
        $generation = $this->makeGeneration($user, [
            'script' => 'Kiki smiled.',
            'use_pronunciations' => true,
            'pronunciation_dictionary' => ['Kiki' => '/ˈkiːki/', 'Momo' => '/ˈmoʊmoʊ/'],
        ]);

        app(GenerationProcessor::class)->process($generation->id);

        $request = $this->ttsRequests()->first();
        $this->assertSame(['Kiki' => '/ˈkiːki/'], $request['pronunciation_dictionary']);
        $this->assertTrue($request['with_timestamps']);
        $this->assertSame('Kiki', $generation->chunks()->first()->words[0]['text']);
    }

    public function test_pronunciations_are_not_sent_on_turbo_free(): void
    {
        $this->fakeTtsWithWords();
        $generation = $this->makeGeneration(attrs: [
            'script' => 'Kiki smiled.', 'model' => 'heygen-voice-1-turbo-free',
            'pronunciation_dictionary' => ['Kiki' => '/ˈkiːki/'],
        ]);

        app(GenerationProcessor::class)->process($generation->id);

        $this->assertArrayNotHasKey('pronunciation_dictionary', $this->ttsRequests()->first()->data());
    }

    public function test_pronunciation_crud_adds_slashes_and_is_owner_scoped(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('pronunciations.store'), ['term' => ' Kiki ', 'ipa' => 'ˈkiːki'])->assertSessionHasNoErrors();
        $entry = PronunciationEntry::query()->firstOrFail();
        $this->assertSame(['Kiki', '/ˈkiːki/'], [$entry->term, $entry->ipa]);

        $this->actingAs($user)->post(route('pronunciations.store'), ['term' => 'kiki', 'ipa' => '/x/'])->assertSessionHasErrors('term');
        $this->actingAs(User::factory()->create())->delete(route('pronunciations.destroy', $entry))->assertForbidden();
        $this->actingAs($user)->delete(route('pronunciations.destroy', $entry))->assertRedirect();
        $this->assertModelMissing($entry);
    }

    public function test_pronunciation_preview_calls_heygen_with_the_term(): void
    {
        $this->fakeTtsWithWords();
        $voice = $this->kikitoonsVoice(['is_default' => true]);
        app(SettingsService::class)->setDefaultVoiceId($voice->voice_id);

        $this->actingAs(User::factory()->create())
            ->postJson(route('pronunciations.preview'), ['term' => 'Kiki', 'ipa' => '/ˈkiːki/'])
            ->assertOk()->assertJsonPath('audio_url', 'https://files.heygen.test/a.mp3');

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/tts') && $r['pronunciation_dictionary'] === ['Kiki' => '/ˈkiːki/'] && $r['voice_id'] === 'voice_kikitoons_123');
    }

    public function test_new_generations_snapshot_the_users_dictionary(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $user->pronunciationEntries()->create(['term' => 'Kiki', 'ipa' => '/ˈkiːki/', 'is_active' => true]);
        $user->pronunciationEntries()->create(['term' => 'Off', 'ipa' => '/ɒf/', 'is_active' => false]);

        $id = $this->actingAs($user)->postJson(route('generations.store'), $this->payload())->assertCreated()->json('id');

        $this->assertSame(['Kiki' => '/ˈkiːki/'], Generation::query()->find($id)->pronunciation_dictionary);
    }

    // ---------------------------------------------------------------- Character voices

    public function test_character_lines_are_generated_with_their_own_voice_and_labels_are_not_spoken(): void
    {
        $this->fakeTtsWithWords();
        Queue::fake();
        $user = User::factory()->create();
        $narrator = $this->kikitoonsVoice();
        $momo = $this->kikitoonsVoice(['voice_id' => 'voice_momo', 'name' => 'Momo voice']);

        $id = $this->actingAs($user)->postJson(route('generations.store'), $this->payload([
            'voice_profile_id' => $narrator->id,
            'script' => "Once upon a time.\nMomo: Hello Kiki!\nNote: not a person.",
            'speakers' => [
                ['key' => 'momo', 'label' => 'Momo', 'voice_profile_id' => $momo->id],
                ['key' => 'note', 'label' => 'Note', 'voice_profile_id' => null],
            ],
            'strip_speaker_labels' => true,
        ]))->assertCreated()->json('id');

        app(GenerationProcessor::class)->process($id);

        $sent = $this->ttsRequests()->map(fn ($r) => [$r['voice_id'], $r['text']])->all();
        $this->assertSame([
            ['voice_kikitoons_123', 'Once upon a time.'],
            ['voice_momo', 'Hello Kiki!'],
            ['voice_kikitoons_123', 'Note: not a person.'],
        ], $sent);
        $this->assertSame("Once upon a time.\nMomo: Hello Kiki!\nNote: not a person.", Generation::query()->find($id)->script, 'The stored script is never modified.');
        $this->assertSame('Momo', Generation::query()->find($id)->chunks[1]->speaker);
    }

    public function test_turbo_model_is_rejected_when_a_character_uses_a_professional_clone(): void
    {
        $pro = $this->kikitoonsVoice(['voice_id' => 'voice_pro', 'name' => 'Pro', 'mode' => 'professional']);

        $this->actingAs(User::factory()->create())->postJson(route('generations.store'), $this->payload([
            'model' => 'heygen-voice-1-turbo',
            'script' => 'Pro: Hi.',
            'speakers' => [['key' => 'pro', 'label' => 'Pro', 'voice_profile_id' => $pro->id]],
        ]))->assertJsonValidationErrors('model');
    }

    // ---------------------------------------------------------------- Subtitle export

    public function test_corrected_and_narration_timed_srt_exports(): void
    {
        $this->fakeTtsWithWords();
        Queue::fake();
        $user = User::factory()->create();
        $upload = $this->actingAs($user)->postJson(route('srt.store'), ['file' => UploadedFile::fake()->createWithContent('s.srt', $this->fixture('sample.srt'))])->json();
        $edited = str_replace('curious little cat', 'curious little kitten', $upload['script']);

        $id = $this->actingAs($user)->postJson(route('generations.store'), $this->payload(['uploaded_file_id' => $upload['id'], 'script' => $edited, 'silence_between_ms' => 500]))->json('id');
        app(SettingsService::class)->set('generation.chunk_max_chars', 500);
        app(GenerationProcessor::class)->process($id);
        $generation = Generation::query()->find($id);

        $corrected = $this->actingAs($user)->get(route('generations.srt', [$generation, 'type' => 'corrected']))->assertOk();
        $this->assertStringContainsString("00:00:03,600 --> 00:00:06,000\nthere lived a curious little kitten named Kiki.", $corrected->getContent());

        $narration = $this->actingAs($user)->get(route('generations.srt', [$generation, 'type' => 'narration']))->assertOk();
        $this->assertStringStartsWith("1\n00:00:00,000 --> ", $narration->getContent());
        $this->assertStringContainsString('Once upon a time, in a small village,', $narration->getContent());

        $this->actingAs(User::factory()->create())->get(route('generations.srt', [$generation, 'type' => 'corrected']))->assertForbidden();
    }

    public function test_wizard_can_download_a_corrected_srt_before_generating(): void
    {
        $user = User::factory()->create();
        $upload = $this->actingAs($user)->postJson(route('srt.store'), ['file' => UploadedFile::fake()->createWithContent('story.srt', $this->fixture('sample.srt'))])->json();

        $response = $this->actingAs($user)->post(route('srt.export', $upload['id']), ['script' => 'Once upon a time, in a big village, there lived a curious little cat named Kiki. Every morning, Kiki asked: "What will we discover today?"']);

        $response->assertOk()->assertHeader('content-disposition', 'attachment; filename="story-corrected.srt"');
        $this->assertStringContainsString("00:00:01,000 --> 00:00:03,500\nOnce upon a time, in a big village,", $response->getContent());
    }

    // ---------------------------------------------------------------- Folders

    public function test_folders_crud_move_and_library_filter(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('folders.store'), ['name' => 'Season 1'])->assertSessionHasNoErrors();
        $folder = Folder::query()->firstOrFail();
        $inFolder = $this->makeGeneration($user, ['project_name' => 'Episode one']);
        $this->makeGeneration($user, ['project_name' => 'Loose story']);

        $this->actingAs($user)->patch(route('generations.folder', $inFolder), ['folder_id' => $folder->id])->assertRedirect();
        $this->assertSame($folder->id, $inFolder->fresh()->folder_id);

        $this->actingAs($user)->get(route('library', ['folder' => $folder->id]))->assertSee('Episode one')->assertDontSee('Loose story');
        $this->actingAs($user)->get(route('library', ['folder' => 'none']))->assertSee('Loose story')->assertDontSee('Episode one');
        $this->actingAs($user)->get(route('folders.index'))->assertOk()->assertSee('Season 1');

        $this->actingAs($user)->delete(route('folders.destroy', $folder))->assertRedirect();
        $this->assertNull($inFolder->fresh()->folder_id, 'Deleting a folder keeps its narrations.');
    }

    public function test_folders_are_private_to_their_owner(): void
    {
        $folder = User::factory()->create()->folders()->create(['name' => 'Secret']);
        $intruder = User::factory()->create();
        $mine = $this->makeGeneration($intruder);

        $this->actingAs($intruder)->delete(route('folders.destroy', $folder))->assertForbidden();
        $this->actingAs($intruder)->patch(route('generations.folder', $mine), ['folder_id' => $folder->id])->assertSessionHasErrors('folder_id');
    }

    // ---------------------------------------------------------------- Batch

    public function test_batch_creates_and_queues_one_generation_per_file(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $voice = $this->kikitoonsVoice();
        $folder = $user->folders()->create(['name' => 'Batch folder']);
        $ids = collect(['a.srt' => 'sample.srt', 'b.srt' => 'khmer.srt'])->map(fn ($fixture, $name) => $this->actingAs($user)
            ->postJson(route('srt.store'), ['file' => UploadedFile::fake()->createWithContent($name, $this->fixture($fixture))])->json('id'))->values();

        $response = $this->actingAs($user)->postJson(route('batches.store'), [
            ...collect($this->payload(['voice_profile_id' => $voice->id]))->except(['script', 'project_name', 'mode'])->all(),
            'name' => 'Week 1', 'mode' => 'continuous', 'folder_id' => $folder->id,
            'items' => [['uploaded_file_id' => $ids[0], 'project_name' => 'Story A'], ['uploaded_file_id' => $ids[1], 'project_name' => 'Story B']],
        ])->assertCreated();

        $batch = GenerationBatch::query()->findOrFail($response->json('id'));
        $this->assertSame(['Story A', 'Story B'], $batch->generations->pluck('project_name')->all());
        $this->assertSame([$folder->id, $folder->id], $batch->generations->pluck('folder_id')->all());
        $this->assertStringContainsString('ថ្ងៃនេះ', $batch->generations[1]->script);
        Queue::assertPushed(ProcessGeneration::class, 2);

        $this->actingAs($user)->getJson(route('batches.status', $batch))->assertOk()->assertJsonPath('counts.pending', 2)->assertJsonCount(2, 'items');
        $this->actingAs($user)->get(route('batches.show', $batch))->assertOk()->assertSee('Story B');
        $this->actingAs(User::factory()->create())->getJson(route('batches.status', $batch))->assertForbidden();
    }

    public function test_batch_rejects_other_users_files(): void
    {
        Queue::fake();
        $other = User::factory()->create();
        $foreign = $this->actingAs($other)->postJson(route('srt.store'), ['file' => UploadedFile::fake()->createWithContent('x.srt', $this->fixture('sample.srt'))])->json('id');

        $this->actingAs(User::factory()->create())->postJson(route('batches.store'), [
            ...collect($this->payload())->except(['script', 'project_name', 'mode'])->all(),
            'name' => 'Nope', 'mode' => 'continuous', 'items' => [['uploaded_file_id' => $foreign, 'project_name' => 'X']],
        ])->assertJsonValidationErrors('items.0.uploaded_file_id');
        Queue::assertNothingPushed();
    }

    // ---------------------------------------------------------------- Music & sound effects

    private function asset(User $user, string $type, string $name): AudioAsset
    {
        $path = "sounds/{$user->id}/{$name}.mp3";
        Storage::disk('local')->put($path, 'audio');

        return $user->audioAssets()->create(['type' => $type, 'name' => $name, 'original_filename' => "{$name}.mp3", 'path' => $path, 'duration_seconds' => 30]);
    }

    public function test_music_chosen_in_the_wizard_is_mixed_and_voice_only_is_kept(): void
    {
        $this->fakeHeyGenSuccess();
        Queue::fake();
        $user = User::factory()->create();
        $music = $this->asset($user, 'music', 'Calm');

        $id = $this->actingAs($user)->postJson(route('generations.store'), $this->payload(['music_asset_id' => $music->id, 'music_volume_db' => -20]))->json('id');
        app(GenerationProcessor::class)->process($id);
        $generation = Generation::query()->find($id);

        $this->assertSame(GenerationStatus::Completed, $generation->status, (string) $generation->error_message);
        $this->assertSame("generations/{$id}/final.mp3", $generation->audio_path);
        $this->assertSame("generations/{$id}/narration.mp3", $generation->voice_audio_path);
        $this->assertSame(-20.0, $this->audio->mixes[0]['music']['volume_db']);

        $this->actingAs($user)->get(route('generations.download', [$generation, 'voice' => 1]))->assertOk()->assertDownload("kiki-and-the-moon-{$id}-voice-only.mp3");
    }

    public function test_remixing_with_sound_effects_uses_no_heygen_requests(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $sfx = $this->asset($user, 'sfx', 'Knock');
        $generation = $this->makeGeneration($user, ['status' => GenerationStatus::Completed, 'audio_path' => 'generations/9/narration.mp3', 'duration_seconds' => 20]);
        Storage::disk('local')->put('generations/9/narration.mp3', 'voice');

        $this->actingAs($user)->postJson(route('generations.mix', $generation), ['sfx' => [['asset_id' => $sfx->id, 'at_ms' => 25000]]])
            ->assertJsonValidationErrors('sfx.0.at_ms'); // after the 20 s narration
        $this->actingAs($user)->postJson(route('generations.mix', $generation), ['sfx' => [['asset_id' => $sfx->id, 'at_ms' => 1200, 'volume_db' => -3]]])->assertOk();

        $generation->refresh();
        $this->assertNull($generation->mix_status, (string) $generation->mix_error);
        $this->assertSame([['path' => Storage::disk('local')->path($sfx->path), 'at_ms' => 1200, 'volume_db' => -3.0]], $this->audio->mixes[0]['sfx']);
        $this->assertSame("generations/{$generation->id}/final.mp3", $generation->audio_path);
        Http::assertNothingSent();

        // Removing everything restores the voice-only audio.
        $this->actingAs($user)->postJson(route('generations.mix', $generation), ['sfx' => []])->assertOk();
        $this->assertSame('generations/9/narration.mp3', $generation->fresh()->audio_path);
    }

    public function test_mix_rejects_other_users_sounds(): void
    {
        $user = User::factory()->create();
        $foreign = $this->asset(User::factory()->create(), 'sfx', 'Theirs');
        $generation = $this->makeGeneration($user, ['status' => GenerationStatus::Completed, 'audio_path' => 'x.mp3']);

        $this->actingAs($user)->postJson(route('generations.mix', $generation), ['sfx' => [['asset_id' => $foreign->id, 'at_ms' => 0]]])
            ->assertJsonValidationErrors('sfx.0.asset_id');
    }

    public function test_sound_upload_validates_audio_and_is_owner_scoped(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('sounds.store'), ['type' => 'music', 'file' => UploadedFile::fake()->create('song.mp3', 200, 'audio/mpeg')])->assertSessionHas('success');
        $asset = AudioAsset::query()->firstOrFail();
        $this->assertSame('song', $asset->name);

        $this->audio->acceptsUploads = false;
        $this->actingAs($user)->post(route('sounds.store'), ['type' => 'sfx', 'file' => UploadedFile::fake()->create('fake.mp3', 10, 'audio/mpeg')])->assertSessionHas('error');
        $this->actingAs($user)->post(route('sounds.store'), ['type' => 'sfx', 'file' => UploadedFile::fake()->create('evil.php', 10)])->assertSessionHasErrors('file');

        $this->actingAs(User::factory()->create())->get(route('sounds.stream', $asset))->assertForbidden();
        $this->actingAs($user)->get(route('sounds.index'))->assertOk()->assertSee('song');
    }

    // ---------------------------------------------------------------- helpers

    private function payload(array $overrides = []): array
    {
        if (! array_key_exists('voice_profile_id', $overrides)) {
            $overrides['voice_profile_id'] = VoiceProfile::query()->where('voice_id', 'voice_kikitoons_123')->value('id') ?? $this->kikitoonsVoice()->id;
        }

        return array_merge([
            'project_name' => 'Kiki and the moon',
            'mode' => 'continuous',
            'script' => 'Kiki looked at the moon.',
            'language' => 'en',
            'model' => 'heygen-voice-1',
            'output_format' => 'mp3',
            'mp3_bitrate' => 128000,
            'silence_between_ms' => 300,
            'normalize_loudness' => true,
        ], $overrides);
    }
}
