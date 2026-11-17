@use('App\Support\Format')
<x-layouts.app title="Sound Library">
    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            @foreach (['music' => ['Background music', $music, 'Looped under the narration, faded in/out and lowered while the voice speaks.'], 'sfx' => ['Sound effects', $sfx, 'Placed at chosen times on a narration (e.g. a door knock at 0:12.5).']] as $type => [$heading, $items, $help])
                <div class="card overflow-hidden">
                    <div class="border-b border-slate-100 px-5 py-4">
                        <h2 class="text-base font-semibold text-slate-900">{{ $heading }} <span class="font-normal text-slate-500">({{ $items->count() }})</span></h2>
                        <p class="text-sm text-slate-500">{{ $help }}</p>
                    </div>
                    @if ($items->isEmpty())
                        <p class="px-5 py-8 text-center text-sm text-slate-500">Nothing uploaded yet.</p>
                    @else
                        <ul class="divide-y divide-slate-100">
                            @foreach ($items as $asset)
                                <li class="flex flex-col gap-3 px-5 py-3 sm:flex-row sm:items-center" x-data="{ editing: false }">
                                    <div class="min-w-0 flex-1" x-show="!editing">
                                        <p class="truncate font-medium text-slate-900">{{ $asset->name }}</p>
                                        <p class="truncate text-xs text-slate-500">{{ $asset->original_filename }} · {{ Format::duration($asset->duration_seconds) }} · {{ number_format($asset->size_bytes / 1048576, 1) }} MB</p>
                                    </div>
                                    <form x-show="editing" x-cloak method="POST" action="{{ route('sounds.update', $asset) }}" class="flex flex-1 gap-2">
                                        @csrf @method('PUT')
                                        <input name="name" value="{{ $asset->name }}" maxlength="150" class="input" aria-label="Sound name">
                                        <button class="btn-primary btn-sm">Save</button>
                                        <button type="button" class="btn-ghost btn-sm" @click="editing = false">Cancel</button>
                                    </form>
                                    <audio controls preload="none" src="{{ route('sounds.stream', $asset) }}" class="h-9 w-full sm:w-56"></audio>
                                    <div class="flex items-center gap-1">
                                        <button type="button" class="btn-ghost btn-sm" @click="editing = true" x-show="!editing">Rename</button>
                                        <x-confirm-form :action="route('sounds.destroy', $asset)" method="DELETE" title="Delete sound?" message="“{{ $asset->name }}” will be deleted. Narrations already mixed with it keep their audio, but can't be re-mixed with it." confirm-label="Delete" :danger="true">
                                            <button class="icon-btn hover:!text-rose-600" aria-label="Delete {{ $asset->name }}"><x-icon name="trash" class="h-4 w-4" /></button>
                                        </x-confirm-form>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endforeach
        </div>

        <aside>
            <form method="POST" action="{{ route('sounds.store') }}" enctype="multipart/form-data" class="card space-y-4 p-6" x-data="{ busy: false }" @submit="busy = true">
                @csrf
                <h2 class="text-base font-semibold text-slate-900">Upload a sound</h2>
                @unless ($ffmpegAvailable)
                    <p class="rounded-lg bg-rose-50 p-3 text-xs text-rose-700">FFmpeg is required to check and mix sounds.</p>
                @endunless
                <fieldset>
                    <legend class="label">Type</legend>
                    <div class="grid grid-cols-2 gap-2">
                        <label class="flex items-center gap-2 rounded-lg border border-slate-200 p-2.5 text-sm"><input type="radio" name="type" value="music" checked class="text-brand-600"> Music</label>
                        <label class="flex items-center gap-2 rounded-lg border border-slate-200 p-2.5 text-sm"><input type="radio" name="type" value="sfx" class="text-brand-600"> Sound effect</label>
                    </div>
                </fieldset>
                <div>
                    <label for="s-file" class="label">File</label>
                    <input id="s-file" type="file" name="file" required accept=".mp3,.wav,.ogg,.m4a,.aac,.flac,audio/*" class="block w-full text-sm text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-brand-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-brand-700 hover:file:bg-brand-100">
                    <p class="hint">MP3, WAV, OGG, M4A, AAC or FLAC · max 30 MB.</p>
                    @error('file')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="s-name" class="label">Name <span class="font-normal text-slate-400">(optional)</span></label>
                    <input id="s-name" name="name" maxlength="150" class="input" placeholder="Defaults to the file name">
                </div>
                <button class="btn-primary w-full" :disabled="busy" @disabled(! $ffmpegAvailable)><x-icon name="upload" class="h-4 w-4" /> <span x-text="busy ? 'Uploading…' : 'Upload'">Upload</span></button>
                <p class="hint">Only upload audio you have the rights to use in your videos.</p>
            </form>
        </aside>
    </div>
</x-layouts.app>
