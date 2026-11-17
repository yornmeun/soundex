<?php

namespace Tests\Feature;

use App\Enums\ChunkStatus;
use App\Enums\GenerationMode;
use App\Enums\GenerationStatus;
use App\Events\GenerationFailed;
use App\Events\GenerationFinished;
use App\Jobs\ProcessGeneration;
use App\Models\Generation;
use App\Services\Generation\GenerationProcessor;
use App\Services\Generation\GenerationService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\Support\BuildsGenerations;
use Tests\TestCase;

class GenerationProcessingTest extends TestCase
{
    use BuildsGenerations, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Sleep::fake();
        $this->useFakeAudio();
        $this->configureHeyGen();
        app(SettingsService::class)->set('generation.chunk_max_chars', 500);
    }

    private function process(int $id): void
    {
        app(GenerationProcessor::class)->process($id);
    }

    public function test_a_generation_completes_and_merges_chunks_in_order(): void
    {
        Event::fake([GenerationFinished::class]);
        $this->fakeHeyGenSuccess();
        $sentences = [];
        for ($i = 1; $i <= 30; $i++) {
            $sentences[] = "Sentence {$i} tells a little more about Kiki the curious cat.";
        }
        $generation = $this->makeGeneration(attrs: ['script' => implode(' ', $sentences)]);

        $this->process($generation->id);

        $generation->refresh();
        $this->assertSame(GenerationStatus::Completed, $generation->status, (string) $generation->error_message);
        $this->assertSame(100, $generation->progress);
        $this->assertGreaterThan(1, $generation->total_chunks);
        $this->assertSame($generation->total_chunks, $generation->completed_chunks);
        $this->assertSame('generations/'.$generation->id.'/narration.mp3', $generation->audio_path);
        $this->assertSame(1.5, $generation->duration_seconds);
        Storage::disk('local')->assertExists($generation->audio_path);

        // Chunks were requested in order and merged in order.
        $this->assertSame($generation->total_chunks, $this->ttsRequestCount());
        $texts = collect(Http::recorded())->map(fn ($p) => $p[0])->filter(fn ($r) => str_contains($r->url(), '/tts'))->map(fn ($r) => $r['text'])->values();
        $this->assertStringStartsWith('Sentence 1 ', $texts->first());
        $this->assertStringEndsWith('Sentence 30 tells a little more about Kiki the curious cat.', $texts->last());
        $mergedInputs = array_map('basename', $this->audio->merged[0]['inputs']);
        $this->assertSame($mergedInputs, collect($mergedInputs)->sort()->values()->all());
        $this->assertSame(300, $this->audio->merged[0]['options']['silence_ms']);

        Event::assertDispatched(GenerationFinished::class);
    }

    public function test_rate_limited_requests_are_retried_after_waiting(): void
    {
        Http::fake([
            'heygen.test/v3/models/audio/tts' => Http::sequence()
                ->push(['error' => ['code' => 'rate_limit_exceeded', 'message' => 'Slow down']], 429, ['Retry-After' => '3'])
                ->push(['data' => ['audio_url' => 'https://files.heygen.test/a.mp3', 'duration' => 1]], 200),
            'files.heygen.test/*' => fn () => Http::response('mp3', 200),
        ]);
        $generation = $this->makeGeneration(attrs: ['script' => 'Short script.']);

        $this->process($generation->id);

        $this->assertSame(GenerationStatus::Completed, $generation->refresh()->status);
        $this->assertSame(2, $generation->chunks()->first()->attempts);
        Sleep::assertSlept(fn ($duration) => (int) $duration->totalSeconds === 3, 1);
    }

    public function test_quota_errors_fail_immediately_without_retrying(): void
    {
        Event::fake([GenerationFailed::class]);
        Http::fake(['heygen.test/*' => Http::response(['error' => ['code' => 'insufficient_credit', 'message' => 'No credits']], 402)]);
        // Professional clones cannot use the free Turbo model, so there is no fallback.
        $generation = $this->makeGeneration(attrs: ['script' => str_repeat('A complete sentence about Kiki. ', 40), 'voice_mode' => 'professional']);

        $this->process($generation->id);

        $generation->refresh();
        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertStringContainsString('Not enough HeyGen credits', $generation->error_message);
        $this->assertSame(1, $this->ttsRequestCount(), 'Billing errors must not be retried.');
        $this->assertSame(ChunkStatus::Failed, $generation->chunks()->first()->status);
        $this->assertSame(0, $generation->chunks()->where('status', ChunkStatus::Completed)->count());
        Sleep::assertNeverSlept();
        Event::assertDispatched(GenerationFailed::class);
    }

    public function test_instant_voices_fall_back_to_the_free_model_when_credits_run_out(): void
    {
        Http::fake([
            'heygen.test/v3/models/audio/tts' => function ($request) {
                return $request['model'] === 'heygen-voice-1-turbo-free'
                    ? Http::response(['data' => ['audio_url' => 'https://files.heygen.test/a.mp3', 'duration' => 1]], 200)
                    : Http::response(['error' => ['code' => 'insufficient_credit', 'message' => 'No credits']], 402);
            },
            'files.heygen.test/*' => fn () => Http::response('mp3', 200),
        ]);
        $generation = $this->makeGeneration(attrs: ['script' => 'Short script.']);

        $this->process($generation->id);

        $generation->refresh();
        $this->assertSame(GenerationStatus::Completed, $generation->status);
        $this->assertSame('heygen-voice-1-turbo-free', $generation->model);
        $this->assertSame(2, $this->ttsRequestCount());
        Sleep::assertNeverSlept();
    }

    public function test_free_model_fallback_can_be_disabled(): void
    {
        config(['kikitoons.heygen.free_model_fallback' => false]);
        Http::fake(['heygen.test/*' => Http::response(['error' => ['code' => 'insufficient_credit', 'message' => 'No credits']], 402)]);
        $generation = $this->makeGeneration(attrs: ['script' => 'Short script.']);

        $this->process($generation->id);

        $this->assertSame(GenerationStatus::Failed, $generation->refresh()->status);
        $this->assertSame(1, $this->ttsRequestCount());
    }

    public function test_transient_errors_stop_after_max_attempts(): void
    {
        config(['kikitoons.heygen.max_attempts' => 3]);
        Http::fake(['heygen.test/*' => Http::response(['error' => ['code' => 'service_unavailable']], 503)]);
        $generation = $this->makeGeneration(attrs: ['script' => 'Short script.']);

        $this->process($generation->id);

        $this->assertSame(GenerationStatus::Failed, $generation->refresh()->status);
        $this->assertSame(3, $this->ttsRequestCount());
        $this->assertSame(3, $generation->chunks()->first()->attempts);
    }

    public function test_retry_only_regenerates_failed_chunks(): void
    {
        $generation = $this->makeGeneration(attrs: ['status' => GenerationStatus::Failed, 'total_chunks' => 3]);
        Storage::disk('local')->put("generations/{$generation->id}/chunks/0000.mp3", 'ok');
        Storage::disk('local')->put("generations/{$generation->id}/chunks/0002.mp3", 'ok');
        $generation->chunks()->create(['chunk_index' => 0, 'text' => 'One.', 'status' => ChunkStatus::Completed, 'audio_path' => "generations/{$generation->id}/chunks/0000.mp3", 'duration_seconds' => 1]);
        $generation->chunks()->create(['chunk_index' => 1, 'text' => 'Two.', 'status' => ChunkStatus::Failed, 'error_message' => 'boom', 'attempts' => 4]);
        $generation->chunks()->create(['chunk_index' => 2, 'text' => 'Three.', 'status' => ChunkStatus::Completed, 'audio_path' => "generations/{$generation->id}/chunks/0002.mp3", 'duration_seconds' => 1]);

        Queue::fake();
        $this->assertTrue(app(GenerationService::class)->retryFailed($generation));
        Queue::assertPushed(ProcessGeneration::class, fn ($job) => $job->generationId === $generation->id);

        $this->fakeHeyGenSuccess();
        $this->process($generation->id);

        $this->assertSame(GenerationStatus::Completed, $generation->refresh()->status);
        $this->assertSame(1, $this->ttsRequestCount());
        Http::assertSent(fn ($r) => str_contains($r->url(), '/tts') && $r['text'] === 'Two.');
        $this->assertSame(['0000.mp3', '0001.mp3', '0002.mp3'], array_map('basename', $this->audio->merged[0]['inputs']));
    }

    public function test_a_generation_cannot_be_processed_twice(): void
    {
        $this->fakeHeyGenSuccess();
        $generation = $this->makeGeneration(attrs: ['script' => 'Short script.']);

        $this->process($generation->id);
        $this->process($generation->id); // already completed -> not claimed again

        $this->assertSame(1, $this->ttsRequestCount());
    }

    public function test_duplicate_jobs_are_not_queued_for_the_same_generation(): void
    {
        Queue::fake();
        $generation = $this->makeGeneration();

        ProcessGeneration::dispatch($generation->id);
        ProcessGeneration::dispatch($generation->id);

        Queue::assertPushed(ProcessGeneration::class, 1);
    }

    public function test_status_updates_progress_through_stages(): void
    {
        $this->fakeHeyGenSuccess();
        $generation = $this->makeGeneration(attrs: ['script' => 'Short script.']);
        $this->assertSame(GenerationStatus::Pending, $generation->status);

        $this->process($generation->id);

        $generation->refresh();
        $this->assertSame('completed', $generation->stage->value);
        $this->assertNotNull($generation->started_at);
        $this->assertNotNull($generation->completed_at);
    }

    public function test_cancelling_a_pending_generation_prevents_processing(): void
    {
        Http::fake();
        $generation = $this->makeGeneration();

        app(GenerationService::class)->cancel($generation);
        $this->process($generation->id);

        $this->assertSame(GenerationStatus::Cancelled, $generation->refresh()->status);
        Http::assertNothingSent();
    }

    public function test_cancellation_requested_while_processing_stops_before_the_next_chunk(): void
    {
        $generation = $this->makeGeneration(attrs: ['script' => str_repeat('A sentence about Kiki and friends. ', 60)]);
        Http::fake([
            'heygen.test/*' => function () use ($generation) {
                // Simulate the user pressing Cancel while the first request is in flight.
                Generation::query()->whereKey($generation->id)->update(['cancel_requested_at' => now()]);

                return Http::response(['data' => ['audio_url' => 'https://files.heygen.test/a.mp3', 'duration' => 1]]);
            },
            'files.heygen.test/*' => fn () => Http::response('mp3'),
        ]);

        $this->process($generation->id);

        $generation->refresh();
        $this->assertSame(GenerationStatus::Cancelled, $generation->status);
        $this->assertSame(1, $this->ttsRequestCount());
        $this->assertSame(1, $generation->chunks()->where('status', ChunkStatus::Completed)->count());
    }

    public function test_missing_ffmpeg_fails_before_calling_heygen(): void
    {
        $this->useFakeAudio(available: false);
        Http::fake();
        $generation = $this->makeGeneration();

        $this->process($generation->id);

        $this->assertSame(GenerationStatus::Failed, $generation->refresh()->status);
        $this->assertStringContainsString('FFmpeg', $generation->error_message);
        Http::assertNothingSent();
    }

    public function test_segments_mode_builds_timing_report_and_bundle(): void
    {
        $this->fakeHeyGenSuccess();
        $generation = $this->makeGeneration(attrs: ['mode' => GenerationMode::Segments, 'total_chunks' => 2, 'script' => "Hi.\nBye."]);
        $generation->chunks()->create(['chunk_index' => 0, 'text' => 'Hi.', 'start_ms' => 1000, 'end_ms' => 3000, 'subtitle_indices' => [1], 'status' => ChunkStatus::Pending]);
        $generation->chunks()->create(['chunk_index' => 1, 'text' => 'Bye.', 'start_ms' => 2000, 'end_ms' => 2500, 'subtitle_indices' => [2], 'status' => ChunkStatus::Pending]);

        $this->process($generation->id);

        $generation->refresh();
        $this->assertSame(GenerationStatus::Completed, $generation->status, (string) $generation->error_message);
        $this->assertCount(2, $generation->timing_report);
        // Fake clips are 1.5 s: the first fits its 2 s window, the second (0.5 s window) does not.
        $this->assertTrue($generation->timing_report[0]['fits_subtitle_window']);
        $this->assertFalse($generation->timing_report[1]['fits_subtitle_window']);
        $this->assertTrue($generation->timing_report[0]['overlaps_next_segment']);
        $this->assertSame('00:00:01,000', $generation->timing_report[0]['start']);
        $this->assertSame([1000, 2000], array_column($this->audio->timelines[0]['clips'], 'start_ms'));

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path($generation->bundle_path)));
        $this->assertNotFalse($zip->locateName('timing_report.csv'));
        $this->assertNotFalse($zip->locateName('clips/001_00-00-01.000.mp3'));
        $zip->close();
    }
}
