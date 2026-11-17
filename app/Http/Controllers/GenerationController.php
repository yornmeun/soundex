<?php

namespace App\Http\Controllers;

use App\Enums\GenerationStage;
use App\Enums\GenerationStatus;
use App\Http\Requests\StoreGenerationRequest;
use App\Models\Generation;
use App\Models\UploadedFile;
use App\Models\VoiceProfile;
use App\Services\Audio\AudioProcessor;
use App\Services\Generation\GenerationService;
use App\Services\SettingsService;
use App\Services\VoiceService;
use App\Support\WorkerHeartbeat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GenerationController extends Controller
{
    public function __construct(private readonly GenerationService $generations) {}

    public function create(SettingsService $settings, VoiceService $voices, AudioProcessor $audio): View
    {
        $voiceList = VoiceProfile::query()->where('is_available', true)->orderByDesc('is_default')->orderBy('name')->get();

        return view('generations.create', [
            'voices' => $voiceList->map(fn (VoiceProfile $v) => [
                'id' => $v->id,
                'name' => $v->name,
                'mode' => $v->mode,
                'language' => $v->language,
                'active' => $v->isActive(),
                'status' => $v->status,
                'is_default' => $v->is_default,
            ])->values(),
            'defaults' => $settings->generationDefaults(),
            'defaultVoice' => $voices->defaultVoice(),
            'hasApiKey' => filled($settings->apiKey()),
            'ffmpegAvailable' => $audio->isAvailable(),
            'workerOnline' => WorkerHeartbeat::isOnline(),
        ]);
    }

    public function store(StoreGenerationRequest $request, AudioProcessor $audio): JsonResponse
    {
        $data = $request->validated();

        if (! $audio->isAvailable()) {
            $message = 'FFmpeg is not installed, so audio cannot be merged. Install it (sudo apt install ffmpeg) or set FFMPEG_BINARY / FFPROBE_BINARY in .env, then try again. No HeyGen credits were used.';

            return response()->json(['message' => $message, 'errors' => ['ffmpeg' => [$message]]], 422);
        }
        $voice = VoiceProfile::query()->findOrFail($data['voice_profile_id']);

        if (! $voice->isActive()) {
            return response()->json([
                'message' => "The voice “{$voice->name}” is not ACTIVE in HeyGen (status: {$voice->status}). Refresh voices or choose another voice.",
                'errors' => ['voice_profile_id' => ['Voice is not active.']],
            ], 422);
        }

        $upload = isset($data['uploaded_file_id']) ? UploadedFile::query()->find($data['uploaded_file_id']) : null;
        $generation = $this->generations->create($request->user(), $data, $voice, $upload);

        return response()->json([
            'id' => $generation->id,
            'status_url' => route('generations.status', $generation),
            'show_url' => route('generations.show', $generation),
        ], 201);
    }

    public function show(Generation $generation): View
    {
        $this->authorize('view', $generation);
        $generation->load('chunks', 'regeneratedFrom');

        return view('generations.show', ['generation' => $generation]);
    }

    public function status(Generation $generation): JsonResponse
    {
        $this->authorize('view', $generation);

        return response()->json($this->statusPayload($generation->fresh()->load('chunks')));
    }

    public function history(Request $request): View
    {
        $filters = $this->filters($request);

        return view('generations.history', [
            'generations' => $this->query($request, $filters)->paginate(15)->withQueryString(),
            'filters' => $filters,
        ]);
    }

    public function library(Request $request): View
    {
        $filters = $this->filters($request);

        return view('generations.library', [
            'generations' => $this->query($request, $filters)->paginate(12)->withQueryString(),
            'filters' => $filters,
        ]);
    }

    public function cancel(Generation $generation): RedirectResponse|JsonResponse
    {
        $this->authorize('update', $generation);

        if (! $generation->status->isActive()) {
            return $this->respond('This generation is not running.', 'error');
        }

        $this->generations->cancel($generation);

        return $this->respond($generation->status === GenerationStatus::Pending
            ? 'Generation cancelled.'
            : 'Cancellation requested. It stops after the audio request in progress finishes.');
    }

    public function retry(Generation $generation): RedirectResponse
    {
        $this->authorize('update', $generation);

        if (! $this->generations->retryFailed($generation)) {
            return back()->with('error', 'Only failed or cancelled generations can be retried.');
        }

        return redirect()->route('generations.show', $generation)
            ->with('success', 'Retrying unfinished chunks. Completed chunks are reused, so no extra HeyGen credits are spent on them.');
    }

    public function regenerate(Generation $generation): RedirectResponse
    {
        $this->authorize('update', $generation);

        $voice = VoiceProfile::query()->where('voice_id', $generation->voice_id)->first();
        if (! $voice || ! $voice->isActive()) {
            return back()->with('error', 'The voice used for this generation is no longer active in HeyGen. Refresh voices first.');
        }

        $copy = $this->generations->regenerate($generation);

        return redirect()->route('generations.show', $copy)->with('success', 'A new generation was queued with the same script and settings.');
    }

    public function destroy(Generation $generation): RedirectResponse
    {
        $this->authorize('view', $generation);

        if ($generation->status->isActive()) {
            return back()->with('error', 'Cancel the generation before deleting it.');
        }

        $this->authorize('delete', $generation);
        $this->generations->delete($generation);

        return redirect()->route('library')->with('success', 'Generation deleted.');
    }

    /** Inline playback (supports HTTP range requests for seeking). */
    public function stream(Generation $generation): BinaryFileResponse
    {
        $this->authorize('view', $generation);
        abort_unless($generation->hasAudio(), 404);

        $path = Storage::disk(config('kikitoons.storage_disk'))->path($generation->audio_path);
        abort_unless(is_file($path), 404);

        return response()->file($path, ['Content-Type' => $generation->audioMimeType(), 'Cache-Control' => 'private, max-age=3600']);
    }

    public function download(Generation $generation): StreamedResponse
    {
        $this->authorize('view', $generation);
        abort_unless($generation->hasAudio(), 404);

        return Storage::disk(config('kikitoons.storage_disk'))->download(
            $generation->audio_path,
            $generation->downloadFilename(),
            ['Content-Type' => $generation->audioMimeType()],
        );
    }

    public function downloadBundle(Generation $generation): StreamedResponse
    {
        $this->authorize('view', $generation);
        abort_unless($generation->bundle_path && $generation->status === GenerationStatus::Completed, 404);

        return Storage::disk(config('kikitoons.storage_disk'))->download($generation->bundle_path, $generation->downloadFilename('zip'));
    }

    private function statusPayload(Generation $g): array
    {
        return [
            'id' => $g->id,
            'status' => $g->status->value,
            'status_label' => $g->status->label(),
            'stage' => $g->stage?->value,
            'stage_label' => $g->stage?->label(),
            'stages' => collect(GenerationStage::visible())->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()]),
            'progress' => $g->progress,
            'completed_chunks' => $g->completed_chunks,
            'total_chunks' => $g->total_chunks,
            'error_message' => $g->error_message,
            'cancel_requested' => $g->cancel_requested_at !== null,
            'worker_online' => WorkerHeartbeat::isOnline(),
            'waiting_seconds' => $g->status === GenerationStatus::Pending ? (int) $g->updated_at->diffInSeconds(now()) : 0,
            'duration_seconds' => $g->duration_seconds,
            'stream_url' => $g->hasAudio() ? route('generations.stream', $g) : null,
            'download_url' => $g->hasAudio() ? route('generations.download', $g) : null,
            'bundle_url' => $g->hasAudio() && $g->bundle_path ? route('generations.bundle', $g) : null,
            'show_url' => route('generations.show', $g),
            'chunks' => $g->chunks->map(fn ($c) => [
                'index' => $c->chunk_index + 1,
                'status' => $c->status->value,
                'chars' => mb_strlen($c->text),
                'duration' => $c->duration_seconds,
                'attempts' => $c->attempts,
                'error' => $c->error_message,
            ]),
        ];
    }

    private function filters(Request $request): array
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(GenerationStatus::class)],
            'sort' => ['nullable', Rule::in(['newest', 'oldest'])],
        ]);

        return ['q' => $validated['q'] ?? null, 'status' => $validated['status'] ?? null, 'sort' => $validated['sort'] ?? 'newest'];
    }

    private function query(Request $request, array $filters)
    {
        return Generation::query()
            ->ownedBy($request->user())
            ->search($filters['q'])
            ->when($filters['status'], fn ($q, $s) => $q->where('status', $s))
            ->orderBy('created_at', $filters['sort'] === 'oldest' ? 'asc' : 'desc')
            ->orderBy('id', $filters['sort'] === 'oldest' ? 'asc' : 'desc');
    }

    private function respond(string $message, string $type = 'success'): RedirectResponse|JsonResponse
    {
        if (request()->expectsJson()) {
            return response()->json(['message' => $message], $type === 'error' ? 422 : 200);
        }

        return back()->with($type, $message);
    }
}
