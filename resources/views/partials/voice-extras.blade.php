{{--
    Character voices, pronunciation, folder and background music options.
    Used inside generationWizard and batchForm (both mix in voiceExtras()).
--}}
<div class="card p-6" x-show="speakers.length" x-cloak>
    <div class="flex items-start gap-3">
        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-600"><x-icon name="users" class="h-5 w-5" /></div>
        <div class="min-w-0 flex-1">
            <h3 class="text-base font-semibold text-slate-900">Character voices</h3>
            <p class="text-sm text-slate-500">Lines that start with a name (<code>Kiki: …</code> or <code>[Kiki] …</code>) were found. Give each character a voice. Anything left on “Not a character” is read exactly as written, label included.</p>
        </div>
    </div>
    <ul class="mt-4 divide-y divide-slate-100 rounded-xl border border-slate-200">
        <template x-for="sp in speakers" :key="sp.key">
            <li class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center">
                <div class="min-w-0 flex-1">
                    <p class="font-medium text-slate-900" x-text="sp.label"></p>
                    <p class="text-xs text-slate-500" x-text="sp.lines + (sp.lines === 1 ? ' line' : ' lines')"></p>
                </div>
                <label class="sr-only" :for="'sp-' + sp.key" x-text="'Voice for ' + sp.label"></label>
                <select :id="'sp-' + sp.key" x-model="speakerMap[sp.key]" class="input sm:w-72">
                    <option value="">Not a character — read as written</option>
                    <template x-for="v in voices.filter(v => v.active)" :key="v.id">
                        <option :value="v.id" x-text="voiceLabel(v, false)" :selected="Number(speakerMap[sp.key]) === v.id"></option>
                    </template>
                </select>
            </li>
        </template>
    </ul>
    <label class="mt-3 flex items-center gap-2 text-sm text-slate-700">
        <input type="checkbox" x-model="stripLabels" class="rounded border-slate-300 text-brand-600">
        Don't speak the character name (remove “Kiki:” from the audio — your script itself is not changed)
    </label>
</div>

<div class="card p-6">
    <h3 class="text-base font-semibold text-slate-900">Extras</h3>
    <div class="mt-4 grid gap-5 md:grid-cols-2">
        {{-- Pronunciation --}}
        <div class="rounded-xl border border-slate-200 p-4">
            <label class="flex items-start gap-3">
                <input type="checkbox" x-model="usePronunciations" class="mt-0.5 rounded border-slate-300 text-brand-600" :disabled="!pronunciationTerms.length">
                <span>
                    <span class="block text-sm font-medium text-slate-800">Use my pronunciation dictionary</span>
                    <span class="text-xs text-slate-500" x-show="pronunciationTerms.length">
                        <span x-text="matchedTerms.length"></span> of your <span x-text="pronunciationTerms.length"></span> terms appear<span x-show="matchedTerms.length">: <span class="font-medium text-slate-700" x-text="matchedTerms.slice(0, 6).join(', ') + (matchedTerms.length > 6 ? '…' : '')"></span></span>
                    </span>
                    <span class="text-xs text-slate-500" x-show="!pronunciationTerms.length">No terms yet — <a href="{{ route('pronunciations.index') }}" class="text-brand-600 underline">add names like “Kiki”</a>.</span>
                    <span class="mt-1 block text-xs text-amber-700" x-show="usePronunciations && pronunciationTerms.length && !pronunciationSupported">HeyGen only applies pronunciations to instant clones on Voice 1 / Turbo; it will be skipped for this voice or model.</span>
                </span>
            </label>
        </div>

        {{-- Folder --}}
        <div>
            <label for="folder" class="label">Project folder</label>
            <select id="folder" x-model="folderId" class="input">
                <option value="">No folder</option>
                @foreach ($folders as $folder)
                    <option value="{{ $folder->id }}">{{ $folder->name }}</option>
                @endforeach
            </select>
            <p class="hint">Manage folders in <a href="{{ route('folders.index') }}" class="text-brand-600 underline">Project Folders</a>.</p>
        </div>

        {{-- Music --}}
        <div class="md:col-span-2">
            <label for="music" class="label">Background music <span class="font-normal text-slate-400">(optional)</span></label>
            <div class="grid gap-3 sm:grid-cols-[1fr_auto_auto] sm:items-center">
                <select id="music" x-model="musicAssetId" class="input">
                    <option value="">No music</option>
                    @foreach ($music as $track)
                        <option value="{{ $track->id }}">{{ $track->name }}</option>
                    @endforeach
                </select>
                <label class="flex items-center gap-2 text-sm text-slate-600" x-show="musicAssetId">
                    Volume <input type="range" min="-40" max="0" step="1" x-model.number="musicVolumeDb" class="w-28 accent-brand-600"> <span class="w-12 tabular-nums" x-text="musicVolumeDb + ' dB'"></span>
                </label>
                <label class="flex items-center gap-2 text-sm text-slate-600" x-show="musicAssetId">
                    <input type="checkbox" x-model="musicDuck" class="rounded border-slate-300 text-brand-600"> Lower under voice
                </label>
            </div>
            <p class="hint">
                @if ($music->isEmpty())
                    Upload tracks in the <a href="{{ route('sounds.index') }}" class="text-brand-600 underline">Sound Library</a>.
                @else
                    Music is looped to the narration length with fades. You can change it — and add sound effects — after generation without using HeyGen credits.
                @endif
            </p>
        </div>
    </div>
</div>
