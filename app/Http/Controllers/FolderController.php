<?php

namespace App\Http\Controllers;

use App\Enums\GenerationStatus;
use App\Http\Requests\StoreFolderRequest;
use App\Models\Folder;
use App\Models\Generation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class FolderController extends Controller
{
    public function index(Request $request): View
    {
        $folders = Folder::query()->ownedBy($request->user())
            ->withCount('generations')
            ->withSum(['generations as seconds' => fn ($q) => $q->where('status', GenerationStatus::Completed)], 'duration_seconds')
            ->orderBy('name')->get();

        return view('folders.index', [
            'folders' => $folders,
            'unfiledCount' => Generation::query()->ownedBy($request->user())->whereNull('folder_id')->count(),
        ]);
    }

    public function store(StoreFolderRequest $request): RedirectResponse
    {
        $folder = $request->user()->folders()->create($request->validated() + ['color' => 'violet']);

        return back()->with('success', "Folder “{$folder->name}” created.");
    }

    public function update(StoreFolderRequest $request, Folder $folder): RedirectResponse
    {
        $this->authorize('update', $folder);
        $folder->update($request->validated());

        return back()->with('success', 'Folder updated.');
    }

    public function destroy(Folder $folder): RedirectResponse
    {
        $this->authorize('delete', $folder);
        $folder->delete(); // generations are kept (folder_id set to null)

        return redirect()->route('folders.index')->with('success', "Folder “{$folder->name}” deleted. Its narrations were kept and are now unfiled.");
    }

    /** Move a generation into a folder (or out of all folders). */
    public function move(Request $request, Generation $generation): RedirectResponse
    {
        $this->authorize('update', $generation);
        $data = $request->validate([
            'folder_id' => ['nullable', 'integer', Rule::exists('folders', 'id')->where('user_id', $request->user()->id)],
        ]);

        $generation->update(['folder_id' => $data['folder_id'] ?? null]);

        return back()->with('success', $generation->folder ? "Moved to “{$generation->folder->name}”." : 'Removed from folder.');
    }
}
