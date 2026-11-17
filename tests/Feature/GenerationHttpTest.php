<?php

namespace Tests\Feature;

use App\Enums\GenerationStatus;
use App\Jobs\ProcessGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Support\WorkerHeartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsGenerations;
use Tests\TestCase;

class GenerationHttpTest extends TestCase
{
    use BuildsGenerations, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->useFakeAudio();
    }

    private function payload(array $overrides = []): array
    {
        if (! array_key_exists('voice_profile_id', $overrides)) {
            $overrides['voice_profile_id'] = $this->kikitoonsVoice()->id;
        }

        return array_merge([
            'project_name' => 'Kiki and the moon',
            'mode' => 'continuous',
            'script' => 'Once upon a time. Kiki looked at the moon.',
            'language' => 'en',
            'model' => 'heygen-voice-1',
            'output_format' => 'mp3',
            'mp3_bitrate' => 128000,
            'silence_between_ms' => 300,
            'normalize_loudness' => true,
            'trim_chunk_silence' => false,
        ], $overrides);
    }

    public function test_creating_a_generation_queues_a_job_without_exposing_the_voice_id(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson(route('generations.store'), $this->payload());

        $response->assertCreated()->assertJsonStructure(['id', 'status_url', 'show_url']);
        $this->assertStringNotContainsString('voice_kikitoons_123', $response->getContent());
        $generation = Generation::query()->findOrFail($response->json('id'));
        $this->assertSame('voice_kikitoons_123', $generation->voice_id);
        $this->assertSame('Once upon a time. Kiki looked at the moon.', $generation->script, 'Script must be stored exactly as submitted.');
        $this->assertNull($generation->speed, 'Speed is not sent for instant clones.');
        Queue::assertPushed(ProcessGeneration::class, fn ($job) => $job->generationId === $generation->id);
    }

    public function test_generation_is_refused_without_ffmpeg_so_no_credits_are_spent(): void
    {
        Queue::fake();
        $this->useFakeAudio(available: false);

        $this->actingAs(User::factory()->create())
            ->postJson(route('generations.store'), $this->payload())
            ->assertUnprocessable()
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'FFmpeg is not installed'));

        $this->assertDatabaseCount('generations', 0);
        Queue::assertNothingPushed();
    }

    public function test_status_reports_whether_the_queue_worker_is_running(): void
    {
        config(['queue.default' => 'database']);
        $owner = User::factory()->create();
        $generation = $this->makeGeneration($owner);

        $this->actingAs($owner)->getJson(route('generations.status', $generation))->assertJsonPath('worker_online', false);

        WorkerHeartbeat::beat();
        $this->actingAs($owner)->getJson(route('generations.status', $generation))->assertJsonPath('worker_online', true);

        $this->travel(5)->minutes();
        $this->actingAs($owner)->getJson(route('generations.status', $generation))->assertJsonPath('worker_online', false);
    }

    public function test_inactive_voice_is_rejected(): void
    {
        Queue::fake();
        $voice = $this->kikitoonsVoice(['status' => 'PENDING']);

        $this->actingAs(User::factory()->create())
            ->postJson(route('generations.store'), $this->payload(['voice_profile_id' => $voice->id]))
            ->assertUnprocessable();
        Queue::assertNothingPushed();
    }

    public function test_unsupported_parameters_are_rejected(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson(route('generations.store'), $this->payload(['language' => 'km', 'mp3_bitrate' => 320000, 'speed' => 3]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['language', 'mp3_bitrate', 'speed']);
    }

    public function test_segments_mode_creates_timed_chunks_from_the_upload(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $uploadId = $this->actingAs($user)->postJson(route('srt.store'), ['file' => UploadedFile::fake()->createWithContent('s.srt', $this->fixture('sample.srt'))])->json('id');

        $id = $this->actingAs($user)->postJson(route('generations.store'), $this->payload([
            'mode' => 'segments', 'script' => null, 'uploaded_file_id' => $uploadId,
            'segment_strategy' => 'subtitle', 'segment_texts' => ['Edited one,', 'Two.', 'Three?'],
        ]))->assertCreated()->json('id');

        $chunks = Generation::query()->findOrFail($id)->chunks;
        $this->assertSame(['Edited one,', 'Two.', 'Three?'], $chunks->pluck('text')->all());
        $this->assertSame([1000, 3600, 9000], $chunks->pluck('start_ms')->all());
    }

    public function test_another_users_upload_cannot_be_used(): void
    {
        $other = User::factory()->create();
        $uploadId = $this->actingAs($other)->postJson(route('srt.store'), ['file' => UploadedFile::fake()->createWithContent('s.srt', $this->fixture('sample.srt'))])->json('id');

        $this->actingAs(User::factory()->create())
            ->postJson(route('generations.store'), $this->payload(['uploaded_file_id' => $uploadId]))
            ->assertJsonValidationErrors('uploaded_file_id');
    }

    public function test_download_and_stream_are_only_available_to_the_owner(): void
    {
        $owner = User::factory()->create();
        $generation = $this->makeGeneration($owner, ['status' => GenerationStatus::Completed, 'audio_path' => 'generations/1/narration.mp3']);
        Storage::disk('local')->put('generations/1/narration.mp3', 'fake-mp3');

        $this->get(route('generations.download', $generation))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get(route('generations.download', $generation))->assertForbidden();
        $this->actingAs(User::factory()->create())->get(route('generations.stream', $generation))->assertForbidden();
        $this->actingAs(User::factory()->create())->getJson(route('generations.status', $generation))->assertForbidden();

        $this->actingAs($owner)->get(route('generations.download', $generation))
            ->assertOk()
            ->assertDownload('kiki-story-'.$generation->id.'.mp3');
        $this->actingAs($owner)->get(route('generations.stream', $generation))->assertOk();
    }

    public function test_download_returns_404_until_audio_is_ready(): void
    {
        $owner = User::factory()->create();
        $generation = $this->makeGeneration($owner, ['status' => GenerationStatus::Processing]);

        $this->actingAs($owner)->get(route('generations.download', $generation))->assertNotFound();
    }

    public function test_status_endpoint_reports_progress(): void
    {
        $owner = User::factory()->create();
        $generation = $this->makeGeneration($owner, ['status' => GenerationStatus::Processing, 'stage' => 'generating', 'progress' => 40, 'total_chunks' => 5, 'completed_chunks' => 2]);

        $this->actingAs($owner)->getJson(route('generations.status', $generation))
            ->assertOk()
            ->assertJson(['status' => 'processing', 'stage' => 'generating', 'stage_label' => 'Generating audio', 'progress' => 40, 'completed_chunks' => 2, 'total_chunks' => 5, 'download_url' => null]);
    }

    public function test_other_users_cannot_delete_regenerate_or_cancel(): void
    {
        $generation = $this->makeGeneration(attrs: ['status' => GenerationStatus::Failed]);
        $intruder = User::factory()->create();

        $this->actingAs($intruder)->delete(route('generations.destroy', $generation))->assertForbidden();
        $this->actingAs($intruder)->post(route('generations.regenerate', $generation))->assertForbidden();
        $this->actingAs($intruder)->post(route('generations.retry', $generation))->assertForbidden();
        $this->actingAs($intruder)->post(route('generations.cancel', $generation))->assertForbidden();
        $this->assertModelExists($generation);
    }

    public function test_owner_can_delete_a_finished_generation_and_its_files(): void
    {
        $owner = User::factory()->create();
        $generation = $this->makeGeneration($owner, ['status' => GenerationStatus::Completed]);
        Storage::disk('local')->put("generations/{$generation->id}/narration.mp3", 'x');

        $this->actingAs($owner)->delete(route('generations.destroy', $generation))->assertRedirect(route('library'));

        $this->assertModelMissing($generation);
        Storage::disk('local')->assertMissing("generations/{$generation->id}/narration.mp3");
    }

    public function test_a_running_generation_cannot_be_deleted(): void
    {
        $owner = User::factory()->create();
        $generation = $this->makeGeneration($owner, ['status' => GenerationStatus::Processing]);

        $this->actingAs($owner)->delete(route('generations.destroy', $generation))->assertRedirect();
        $this->assertModelExists($generation);
    }

    public function test_regenerate_creates_a_new_queued_generation(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $this->kikitoonsVoice();
        $original = $this->makeGeneration($owner, ['status' => GenerationStatus::Completed, 'audio_path' => 'x.mp3', 'duration_seconds' => 12]);

        $this->actingAs($owner)->post(route('generations.regenerate', $original))->assertRedirect();

        $copy = Generation::query()->where('regenerated_from_id', $original->id)->firstOrFail();
        $this->assertSame(GenerationStatus::Pending, $copy->status);
        $this->assertSame($original->script, $copy->script);
        $this->assertNull($copy->audio_path);
        Queue::assertPushed(ProcessGeneration::class, fn ($j) => $j->generationId === $copy->id);
    }

    public function test_library_and_history_only_list_own_generations_with_search_and_filters(): void
    {
        $me = User::factory()->create();
        $this->makeGeneration($me, ['project_name' => 'Moon story', 'status' => GenerationStatus::Completed]);
        $this->makeGeneration($me, ['project_name' => 'Sun story', 'status' => GenerationStatus::Failed]);
        $this->makeGeneration(User::factory()->create(), ['project_name' => 'Secret story']);

        $this->actingAs($me)->get(route('library'))->assertOk()->assertSee('Moon story')->assertSee('Sun story')->assertDontSee('Secret story');
        $this->actingAs($me)->get(route('library', ['q' => 'Moon']))->assertSee('Moon story')->assertDontSee('Sun story');
        $this->actingAs($me)->get(route('history', ['status' => 'failed']))->assertSee('Sun story')->assertDontSee('Moon story');
        $this->actingAs($me)->get(route('history', ['sort' => 'oldest']))->assertOk()->assertSeeInOrder(['Moon story', 'Sun story']);
    }

    public function test_all_pages_render(): void
    {
        $me = User::factory()->create();
        $generation = $this->makeGeneration($me);
        $this->actingAs($me);

        foreach (['dashboard', 'generations.create', 'library', 'history', 'settings.voices', 'settings.api', 'settings.general'] as $route) {
            $this->get(route($route))->assertOk();
        }
        $this->get(route('generations.show', $generation))->assertOk()->assertSee('Kiki story');
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->get(route('login'))->assertOk();
    }

    public function test_user_can_log_in(): void
    {
        $user = User::factory()->create(['password' => 'secret-password']);

        $this->post(route('login'), ['email' => $user->email, 'password' => 'secret-password'])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }
}
