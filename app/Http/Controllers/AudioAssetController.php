<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAudioAssetRequest;
use App\Models\AudioAsset;
use App\Services\Audio\AudioProcessor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AudioAssetController extends Controller
{
    public function index(Request $request, AudioProcessor $audio): View
    {
        $assets = AudioAsset::query()->ownedBy($request->user())->orderBy('type')->orderBy('name')->get();

        return view('sounds.index', [
            'music' => $assets->where('type', AudioAsset::TYPE_MUSIC),
            'sfx' => $assets->where('type', AudioAsset::TYPE_SFX),
            'ffmpegAvailable' => $audio->isAvailable(),
        ]);
    }

    public function store(StoreAudioAssetRequest $request, AudioProcessor $audio): RedirectResponse
    {
        $file = $request->file('file');
        $disk = Storage::disk(config('soundex.storage_disk'));
        $ext = strtolower($file->getClientOriginalExtension() ?: 'mp3');
        $path = $file->storeAs('sounds/'.$request->user()->id, Str::uuid().'.'.$ext, config('soundex.storage_disk'));

        // Verify the content really is audio (not just the extension).
        if (! $audio->isAudioFile($disk->path($path))) {
            $disk->delete($path);

            return back()->with('error', 'That file does not contain a readable audio track.');
        }

        $name = $request->validated('name') ?: Str::of($file->getClientOriginalName())->beforeLast('.')->limit(150, '')->toString();

        $request->user()->audioAssets()->create([
            'type' => $request->validated('type'),
            'name' => $name,
            'original_filename' => mb_substr($file->getClientOriginalName(), 0, 255),
            'path' => $path,
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'duration_seconds' => $audio->probeDuration($disk->path($path)),
        ]);

        return back()->with('success', "“{$name}” added to your sound library.");
    }

    public function update(Request $request, AudioAsset $sound): RedirectResponse
    {
        $this->authorize('update', $sound);
        $sound->update($request->validate(['name' => ['required', 'string', 'max:150']]));

        return back()->with('success', 'Sound renamed.');
    }

    public function destroy(AudioAsset $sound): RedirectResponse
    {
        $this->authorize('delete', $sound);
        Storage::disk(config('soundex.storage_disk'))->delete($sound->path);
        $sound->delete();

        return back()->with('success', 'Sound deleted. Narrations that already used it keep their mixed audio.');
    }

    public function stream(AudioAsset $sound): BinaryFileResponse
    {
        $this->authorize('view', $sound);
        $path = Storage::disk(config('soundex.storage_disk'))->path($sound->path);
        abort_unless(is_file($path), 404);

        return response()->file($path, ['Content-Type' => $sound->mime_type ?: 'audio/mpeg']);
    }
}
