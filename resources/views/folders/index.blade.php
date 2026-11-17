@use('App\Support\Format')
<x-layouts.app title="Project Folders">
    <div class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            @if ($folders->isEmpty())
                <div class="card">
                    <x-empty-state icon="folder" title="No folders yet" message="Create a folder for each video or series to keep your narrations organized." />
                </div>
            @else
                <div class="grid gap-4 sm:grid-cols-2">
                    @foreach ($folders as $folder)
                        <div class="card flex flex-col p-5" x-data="{ editing: false }">
                            <div class="flex items-start gap-3" x-show="!editing">
                                <span class="mt-1.5 h-3 w-3 shrink-0 rounded-full {{ $folder->dotClass() }}"></span>
                                <div class="min-w-0 flex-1">
                                    <a href="{{ route('library', ['folder' => $folder->id]) }}" class="block truncate font-semibold text-slate-900 hover:text-brand-700">{{ $folder->name }}</a>
                                    @if ($folder->description)<p class="mt-0.5 line-clamp-2 text-sm text-slate-500">{{ $folder->description }}</p>@endif
                                    <p class="mt-2 text-xs text-slate-500">{{ $folder->generations_count }} narration(s) · {{ Format::humanDuration((float) $folder->seconds) }} of audio</p>
                                </div>
                            </div>
                            <form x-show="editing" x-cloak method="POST" action="{{ route('folders.update', $folder) }}" class="space-y-3">
                                @csrf @method('PUT')
                                <input name="name" value="{{ $folder->name }}" maxlength="100" class="input" required aria-label="Folder name">
                                <textarea name="description" rows="2" maxlength="500" class="input" placeholder="Description (optional)" aria-label="Description">{{ $folder->description }}</textarea>
                                <div class="flex flex-wrap gap-2">
                                    @foreach (\App\Models\Folder::COLORS as $color)
                                        <label class="cursor-pointer"><input type="radio" name="color" value="{{ $color }}" class="peer sr-only" @checked($folder->color === $color)>
                                            <span class="block h-6 w-6 rounded-full ring-2 ring-transparent ring-offset-2 peer-checked:ring-slate-400 {{ (new \App\Models\Folder(['color' => $color]))->dotClass() }}" title="{{ $color }}"></span></label>
                                    @endforeach
                                </div>
                                <div class="flex gap-2"><button class="btn-primary btn-sm">Save</button><button type="button" class="btn-ghost btn-sm" @click="editing = false">Cancel</button></div>
                            </form>
                            <div class="mt-4 flex items-center gap-1 border-t border-slate-100 pt-3" x-show="!editing">
                                <a href="{{ route('library', ['folder' => $folder->id]) }}" class="btn-secondary btn-sm">Open</a>
                                <button type="button" class="btn-ghost btn-sm" @click="editing = true">Rename</button>
                                <x-confirm-form :action="route('folders.destroy', $folder)" method="DELETE" title="Delete folder?" message="The folder is removed. Its narrations are kept and become unfiled." confirm-label="Delete folder" :danger="true" class="ml-auto">
                                    <button class="icon-btn hover:!text-rose-600" title="Delete folder" aria-label="Delete folder {{ $folder->name }}"><x-icon name="trash" class="h-4 w-4" /></button>
                                </x-confirm-form>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
            <p class="mt-4 text-sm text-slate-500"><a href="{{ route('library', ['folder' => 'none']) }}" class="text-brand-600 hover:underline">{{ $unfiledCount }} unfiled narration(s)</a></p>
        </div>

        <aside>
            <form method="POST" action="{{ route('folders.store') }}" class="card space-y-4 p-6">
                @csrf
                <h2 class="text-base font-semibold text-slate-900">New folder</h2>
                <div>
                    <label for="f-name" class="label">Name</label>
                    <input id="f-name" name="name" maxlength="100" required class="input" placeholder="e.g. Kiki Season 1" value="{{ old('name') }}">
                    @error('name')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="f-desc" class="label">Description <span class="font-normal text-slate-400">(optional)</span></label>
                    <textarea id="f-desc" name="description" rows="2" maxlength="500" class="input">{{ old('description') }}</textarea>
                </div>
                <button class="btn-primary w-full"><x-icon name="plus" class="h-4 w-4" /> Create folder</button>
                <p class="hint">Assign a folder when you generate, or move a narration from its details page.</p>
            </form>
        </aside>
    </div>
</x-layouts.app>
