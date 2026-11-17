<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBatchRequest;
use App\Models\AudioAsset;
use App\Models\GenerationBatch;
use App\Models\VoiceProfile;
use App\Services\Audio\AudioProcessor;
use App\Services\Generation\BatchService;
use App\Services\SettingsService;
use App\Services\VoiceService;
use App\Support\Format;
use App\Support\WorkerHeartbeat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BatchController extends Controller
{
    public function index(Request $request): View
    {
        return view('batches.index', [
            'batches' => GenerationBatch::query()->where('user_id', $request->user()->id)
                ->with('generations:id,batch_id,status')->latest()->paginate(15),
        ]);
    }

    public function create(Request $request, SettingsService $settings, VoiceService $voices, AudioProcessor $audio): View
    {
        return view('batches.create', [
            'voices' => VoiceProfile::query()->where('is_available', true)->orderByDesc('is_default')->orderBy('name')->get()
                ->map(fn (VoiceProfile $v) => ['id' => $v->id, 'name' => $v->name, 'mode' => $v->mode, 'active' => $v->isActive(), 'status' => $v->status, 'is_default' => $v->is_default])->values(),
            'defaults' => $settings->generationDefaults(),
            'defaultVoice' => $voices->defaultVoice(),
            'folders' => $request->user()->folders()->get(['id', 'name']),
            'music' => AudioAsset::query()->ownedBy($request->user())->where('type', AudioAsset::TYPE_MUSIC)->orderBy('name')->get(['id', 'name']),
            'ffmpegAvailable' => $audio->isAvailable(),
            'workerOnline' => WorkerHeartbeat::isOnline(),
        ]);
    }

    public function store(StoreBatchRequest $request, BatchService $batches, AudioProcessor $audio): JsonResponse
    {
        $data = $request->validated();
        if (! empty($data['music_asset_id']) && ! $audio->canMix()) {
            return response()->json(['message' => 'Background music needs FFmpeg, which is not installed. Choose "No music" (the narration still works without FFmpeg), or install FFmpeg. No HeyGen credits were used.'], 422);
        }

        $voice = VoiceProfile::query()->findOrFail($data['voice_profile_id']);
        if (! $voice->isActive()) {
            return response()->json(['message' => "The voice “{$voice->name}” is not active in HeyGen."], 422);
        }

        $batch = $batches->create($request->user(), $data, $voice);

        return response()->json(['id' => $batch->id, 'show_url' => route('batches.show', $batch)], 201);
    }

    public function show(GenerationBatch $batch): View
    {
        $this->authorize('view', $batch);
        $batch->load('generations', 'folder');

        return view('batches.show', ['batch' => $batch]);
    }

    public function status(GenerationBatch $batch): JsonResponse
    {
        $this->authorize('view', $batch);
        $batch->load('generations');

        return response()->json([
            'counts' => $batch->statusCounts(),
            'worker_online' => WorkerHeartbeat::isOnline(),
            'items' => $batch->generations->map(fn ($g) => [
                'id' => $g->id,
                'status' => $g->status->value,
                'status_label' => $g->status->label(),
                'stage_label' => $g->stage?->label(),
                'progress' => $g->progress,
                'duration' => Format::duration($g->duration_seconds),
                'error' => $g->error_message,
            ]),
        ]);
    }
}
