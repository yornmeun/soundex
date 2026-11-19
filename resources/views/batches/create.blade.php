<x-layouts.app title="Batch Generation">
    @php
        $config = [
            'voices' => $voices,
            'defaults' => $defaults,
            'defaultVoiceProfileId' => $defaultVoice?->isActive() ? $defaultVoice->id : null,
            'maxUploadKb' => (int) $defaults['upload.max_kb'],
            'uploadUrl' => route('srt.store'),
            'storeUrl' => route('batches.store'),
            'ffmpegAvailable' => $ffmpegAvailable,
            'pronunciationTerms' => auth()->user()->pronunciationEntries()->where('is_active', true)->pluck('term'),
        ];
    @endphp

    <div x-data="batchForm(@js($config))" class="space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="max-w-2xl text-sm text-slate-600">Generate several stories at once. Each SRT becomes its own narration with the same voice and settings, processed one after another by the queue worker.</p>
            <a href="{{ route('batches.index') }}" class="btn-ghost btn-sm"><x-icon name="clock" class="h-4 w-4" /> Previous batches</a>
        </div>

        @unless ($workerOnline)
            <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                <strong>The background worker is not running.</strong> Batches wait in the queue until you start it:
                <code class="ml-1 rounded bg-white px-1.5 py-0.5 font-mono text-xs ring-1 ring-amber-200">php artisan queue:work --timeout=3600 --tries=1</code>
            </div>
        @endunless

        {{-- Files --}}
        <section class="card p-6" aria-labelledby="b-files">
            <h2 id="b-files" class="text-lg font-semibold text-slate-900">1. Add SRT files</h2>
            <label for="batch-files" class="mt-4 flex cursor-pointer flex-col items-center justify-center rounded-2xl border-2 border-dashed px-6 py-10 text-center transition"
                   :class="dragging ? 'border-brand-500 bg-brand-50' : 'border-slate-300 hover:border-brand-400 hover:bg-slate-50'"
                   @dragover.prevent="dragging = true" @dragleave.prevent="dragging = false" @drop.prevent="onDrop($event)">
                <div class="mb-3 flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-50 text-brand-600"><x-icon name="upload" class="h-6 w-6" /></div>
                <p class="font-medium text-slate-900">Drop several .srt files here</p>
                <p class="mt-1 text-sm text-slate-500">or <span class="font-medium text-brand-600 underline">browse</span> — up to 50 files</p>
                <input id="batch-files" type="file" accept=".srt" multiple class="sr-only" @change="onPick($event)">
            </label>

            <ul x-show="items.length" class="mt-5 divide-y divide-slate-100 rounded-xl border border-slate-200">
                <template x-for="item in items" :key="item.key">
                    <li class="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center">
                        <div class="min-w-0 sm:w-56">
                            <p class="truncate text-sm font-medium text-slate-800" x-text="item.file.name" :title="item.file.name"></p>
                            <p class="text-xs text-slate-500">
                                <span x-show="item.status === 'uploading'">Reading subtitles…</span>
                                <span x-show="item.status === 'ready'" x-text="item.upload ? item.upload.subtitle_count + ' subtitles · ' + item.upload.word_count + ' words · ≈ ' + fmt(item.upload.estimated_seconds) : ''"></span>
                                <span x-show="item.status === 'error'" class="text-rose-600" x-text="item.error"></span>
                            </p>
                        </div>
                        <div class="flex-1">
                            <label class="sr-only" :for="'name-' + item.key">Project name</label>
                            <input :id="'name-' + item.key" x-model="item.name" maxlength="150" class="input" :disabled="item.status !== 'ready'" placeholder="Project name">
                        </div>
                        <details x-show="item.status === 'ready'" class="text-xs sm:w-28">
                            <summary class="cursor-pointer text-brand-600">Preview</summary>
                            <p class="mt-2 max-h-40 overflow-y-auto whitespace-pre-wrap rounded bg-slate-50 p-2 text-slate-600 sm:absolute sm:right-10 sm:z-10 sm:w-96 sm:shadow-lg" x-text="item.upload?.script"></p>
                        </details>
                        <button type="button" class="icon-btn" @click="remove(item)" :aria-label="'Remove ' + item.file.name"><x-icon name="x" class="h-4 w-4" /></button>
                    </li>
                </template>
            </ul>
            <p x-show="ready.length" class="mt-3 text-sm text-slate-500"><span x-text="ready.length"></span> file(s) ready · ≈ <span x-text="fmt(totalSeconds)"></span> of narration in total</p>
            <p class="hint mt-2">Batch generation uses each file's text exactly as extracted. To edit a script first, use <a href="{{ route('generations.create') }}" class="text-brand-600 underline">New Generation</a>.</p>
        </section>

        {{-- Settings --}}
        <section class="card p-6" aria-labelledby="b-settings">
            <h2 id="b-settings" class="text-lg font-semibold text-slate-900">2. Shared settings</h2>
            <div class="mt-5 grid gap-5 md:grid-cols-2">
                <div class="md:col-span-2">
                    <label for="b-name" class="label">Batch name</label>
                    <input id="b-name" x-model="name" maxlength="150" class="input">
                </div>
                <div>
                    <label for="b-voice" class="label">Voice</label>
                    <select id="b-voice" x-model.number="form.voice_profile_id" class="input">
                        <option value="">— Choose a voice —</option>
                        <template x-for="v in voices" :key="v.id">
                            <option :value="v.id" :disabled="!v.active" :selected="v.id === form.voice_profile_id" x-text="voiceLabel(v)"></option>
                        </template>
                    </select>
                    <p class="hint text-amber-700" x-show="containsKhmer && selectedVoice && !isMicrosoft(selectedVoice)">
                        These SRTs contain Khmer. For natural Khmer speech choose a Microsoft Khmer voice (Sreymom or Piseth).
                    </p>
                </div>
                <div>
                    <label for="b-mode" class="label">Mode</label>
                    <select id="b-mode" x-model="mode" class="input">
                        <option value="continuous">Continuous narration</option>
                        <option value="segments">Subtitle-synchronized segments</option>
                    </select>
                </div>
                <div>
                    <label for="b-language" class="label">Language</label>
                    <select id="b-language" x-model="form.language" class="input">
                        <option value="auto">Auto-detect</option>
                        @foreach (config('soundex.languages') as $code => $lang)
                            <option value="{{ $code }}">{{ $lang }} ({{ $code }})</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="b-model" class="label">HeyGen model</label>
                    <select id="b-model" x-model="form.model" class="input">
                        @foreach (config('soundex.models') as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label for="b-format" class="label">Output</label>
                        <select id="b-format" x-model="form.output_format" class="input">
                            @foreach (config('soundex.output_formats') as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div x-show="form.output_format === 'mp3'">
                        <label for="b-bitrate" class="label">MP3 quality</label>
                        <select id="b-bitrate" x-model.number="form.mp3_bitrate" class="input">
                            @foreach (config('soundex.mp3_bitrates') as $b)
                                <option value="{{ $b }}">{{ $b / 1000 }} kbps</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div x-show="mode === 'continuous'">
                    <label for="b-silence" class="label">Pause between chunks (ms)</label>
                    <input id="b-silence" type="number" min="0" max="5000" step="50" x-model.number="form.silence_between_ms" class="input">
                </div>
                <div x-show="mode === 'segments'">
                    <label for="b-strategy" class="label">Group subtitles by</label>
                    <select id="b-strategy" x-model="segmentStrategy" class="input">
                        <option value="sentence">Sentences</option>
                        <option value="subtitle">Individual subtitles</option>
                    </select>
                </div>
                <label class="flex items-start gap-3 rounded-lg border border-slate-200 p-3">
                    <input type="checkbox" x-model="form.normalize_loudness" class="mt-0.5 rounded border-slate-300 text-brand-600">
                    <span class="text-sm font-medium text-slate-800">Normalize loudness</span>
                </label>
            </div>
        </section>

        @include('partials.voice-extras')

        <div class="card flex flex-col gap-4 p-6 sm:flex-row sm:items-center">
            <p class="flex-1 text-sm text-slate-600"><span x-text="ready.length"></span> narration(s) will be queued. Each one uses HeyGen credits like a normal generation.</p>
            <button type="button" class="btn-primary" @click="submit()" :disabled="submitting || blockReason !== null">
                <x-icon name="sparkles" class="h-4 w-4" /> <span x-text="submitting ? 'Queuing…' : 'Generate ' + ready.length + ' narration(s)'"></span>
            </button>
        </div>
        <p x-show="!submitting && blockReason" x-text="blockReason" class="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800"></p>
        <p x-show="submitError" x-text="submitError" role="alert" class="rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-700"></p>
    </div>
</x-layouts.app>
