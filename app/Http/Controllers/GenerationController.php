<?php

namespace App\Http\Controllers;

use App\Enums\GenerationMode;
use App\Enums\GenerationStage;
use App\Enums\GenerationStatus;
use App\Http\Requests\StoreGenerationRequest;
use App\Http\Requests\UpdateMixRequest;
use App\Jobs\MixGeneration;
use App\Models\AudioAsset;
use App\Models\Generation;
use App\Models\UploadedFile;
use App\Models\VoiceProfile;
use App\Services\Audio\AudioProcessor;
use App\Services\Generation\GenerationService;
use App\Services\SettingsService;
use App\Services\Srt\NarrationSrtBuilder;
use App\Services\Srt\SrtCue;
use App\Services\Srt\SrtWriter;
use App\Services\Srt\SubtitleAligner;
use App\Services\VoiceService;
use App\Support\Format;
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
            'folders' => request()->user()->folders()->get(['id', 'name']),
            'music' => AudioAsset::query()->ownedBy(request()->user())->where('type', AudioAsset::TYPE_MUSIC)->orderBy('name')->get(['id', 'name']),
            'pronunciationCount' => request()->user()->pronunciationEntries()->where('is_active', true)->count(),
            'pronunciationTerms' => request()->user()->pronunciationEntries()->where('is_active', true)->pluck('term'),
        ]);
    }

    public function store(StoreGenerationRequest $request, AudioProcessor $audio): JsonResponse
    {
        $data = $request->validated();

        if (! empty($data['music_asset_id']) && ! $audio->canMix()) {
            $message = 'Background music needs FFmpeg, which is not installed. Choose "No music" (the narration still works without FFmpeg), or install FFmpeg. No HeyGen credits were used.';

            return response()->json(['message' => $message, 'errors' => ['music_asset_id' => [$message]]], 422);
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
        $generation->load('chunks', 'regeneratedFrom', 'folder', 'batch');
        $assets = AudioAsset::query()->ownedBy(request()->user())->orderBy('name')->get(['id', 'name', 'type', 'duration_seconds']);

        return view('generations.show', [
            'generation' => $generation,
            'folders' => request()->user()->folders()->get(['id', 'name']),
            'musicAssets' => $assets->where('type', AudioAsset::TYPE_MUSIC)->values(),
            'sfxAssets' => $assets->where('type', AudioAsset::TYPE_SFX)->values(),
            'hasWordTimings' => $generation->hasWordTimings(),
        ]);
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
            'generations' => $this->query($request, $filters)->with('folder')->paginate(12)->withQueryString(),
            'filters' => $filters,
            'folders' => $request->user()->folders()->get(['id', 'name']),
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

        $path = Storage::disk(config('soundex.storage_disk'))->path($generation->audio_path);
        abort_unless(is_file($path), 404);

        return response()->file($path, ['Content-Type' => $generation->audioMimeType(), 'Cache-Control' => 'private, max-age=3600']);
    }

    public function download(Request $request, Generation $generation): StreamedResponse
    {
        $this->authorize('view', $generation);
        abort_unless($generation->hasAudio(), 404);

        $voiceOnly = $request->boolean('voice');
        $path = $voiceOnly ? $generation->voiceAudioPath() : $generation->audio_path;
        abort_unless($path && Storage::disk(config('soundex.storage_disk'))->exists($path), 404);

        $name = $voiceOnly
            ? str_replace('.'.$generation->output_format, '-voice-only.'.$generation->output_format, $generation->downloadFilename())
            : $generation->downloadFilename();

        return Storage::disk(config('soundex.storage_disk'))->download($path, $name, ['Content-Type' => $generation->audioMimeType()]);
    }

    /**
     * Subtitle export.
     *  - type=corrected: the (edited) script mapped onto the original subtitle timings
     *  - type=narration: timed to the generated audio from HeyGen's word timestamps
     */
    public function srt(Request $request, Generation $generation, SubtitleAligner $aligner, NarrationSrtBuilder $narration, SrtWriter $writer)
    {
        $this->authorize('view', $generation);
        $type = $request->validate(['type' => ['required', Rule::in(['corrected', 'narration'])]])['type'];

        if ($type === 'narration') {
            abort_unless($generation->hasWordTimings(), 404, 'Narration timings are not available for this generation.');
            $cues = $narration->build($generation);
        } elseif ($generation->mode === GenerationMode::Segments) {
            $cues = $generation->chunks->map(fn ($c) => ['start_ms' => (int) $c->start_ms, 'end_ms' => (int) $c->end_ms, 'text' => $c->text])->all();
        } else {
            $upload = $generation->uploadedFile;
            abort_unless($upload && ! empty($upload->cues), 404, 'The original SRT for this generation is no longer available.');
            $cues = $aligner->align(array_map(fn ($c) => SrtCue::fromArray($c), $upload->cues), $generation->script);
        }

        $filename = $generation->downloadFilename('srt');
        $filename = str_replace('.srt', $type === 'narration' ? '-narration.srt' : '-corrected.srt', $filename);

        return response($writer->write($cues), 200, [
            'Content-Type' => 'application/x-subrip; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /** Save music / sound-effect settings and re-mix in the background (no HeyGen credits). */
    public function mix(UpdateMixRequest $request, Generation $generation): RedirectResponse|JsonResponse
    {
        $this->authorize('update', $generation);
        if (! $generation->hasAudio()) {
            return $this->respond('The narration must be completed before adding music or sound effects.', 'error');
        }
        if ($generation->isMixing()) {
            return $this->respond('A mix is already in progress.', 'error');
        }

        $d = $request->validated();

        if ((! empty($d['music_asset_id']) || ! empty($d['sfx'])) && ! app(AudioProcessor::class)->canMix()) {
            return $this->respond('Background music and sound effects need FFmpeg, which is not installed on this computer.', 'error');
        }

        $limitMs = (int) floor(((float) $generation->duration_seconds) * 1000);
        foreach ($d['sfx'] ?? [] as $i => $effect) {
            if ($limitMs > 0 && (int) $effect['at_ms'] >= $limitMs) {
                $message = 'Sound effect #'.($i + 1).' starts after the narration ends ('.Format::duration($generation->duration_seconds).').';

                return $request->expectsJson()
                    ? response()->json(['message' => $message, 'errors' => ["sfx.{$i}.at_ms" => [$message]]], 422)
                    : back()->with('error', $message);
            }
        }

        $music = empty($d['music_asset_id']) ? null : [
            'asset_id' => (int) $d['music_asset_id'],
            'volume_db' => (float) ($d['music_volume_db'] ?? -18),
            'duck' => (bool) ($d['music_duck'] ?? true),
            'fade_in' => (float) ($d['music_fade_in'] ?? 2),
            'fade_out' => (float) ($d['music_fade_out'] ?? 3),
        ];
        $sfx = collect($d['sfx'] ?? [])->map(fn ($e) => [
            'asset_id' => (int) $e['asset_id'],
            'at_ms' => (int) $e['at_ms'],
            'volume_db' => (float) ($e['volume_db'] ?? 0),
        ])->sortBy('at_ms')->values()->all();

        $generation->update([
            'mix_settings' => ['music' => $music, 'sfx' => $sfx],
            'voice_audio_path' => $generation->voiceAudioPath(),
            'mix_status' => 'pending',
            'mix_error' => null,
        ]);
        MixGeneration::dispatch($generation->id);

        return $this->respond($music || $sfx ? 'Mixing music and sound effects… (no HeyGen credits are used)' : 'Removing music and sound effects…');
    }

    public function downloadBundle(Generation $generation): StreamedResponse
    {
        $this->authorize('view', $generation);
        abort_unless($generation->bundle_path && $generation->status === GenerationStatus::Completed, 404);

        return Storage::disk(config('soundex.storage_disk'))->download($generation->bundle_path, $generation->downloadFilename('zip'));
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
            'voice_download_url' => $g->hasAudio() && $g->hasMix() ? route('generations.download', [$g, 'voice' => 1]) : null,
            'mix_status' => $g->mix_status,
            'mix_error' => $g->mix_error,
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
            'folder' => ['nullable', 'string', 'max:20'], // folder id or "none"
        ]);

        return [
            'q' => $validated['q'] ?? null,
            'status' => $validated['status'] ?? null,
            'sort' => $validated['sort'] ?? 'newest',
            'folder' => $validated['folder'] ?? null,
        ];
    }

    private function query(Request $request, array $filters)
    {
        return Generation::query()
            ->ownedBy($request->user())
            ->search($filters['q'])
            ->when($filters['status'], fn ($q, $s) => $q->where('status', $s))
            ->when($filters['folder'] ?? null, fn ($q, $f) => $f === 'none' ? $q->whereNull('folder_id') : $q->where('folder_id', (int) $f))
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
