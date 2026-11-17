<x-layouts.app title="New Generation">
    @php
        $config = [
            'voices' => $voices,
            'defaults' => $defaults,
            'defaultVoiceProfileId' => $defaultVoice?->isActive() ? $defaultVoice->id : null,
            'maxUploadKb' => (int) $defaults['upload.max_kb'],
            'uploadUrl' => route('srt.store'),
            'segmentsUrl' => route('srt.segments', ['uploadedFile' => '__ID__']),
            'storeUrl' => route('generations.store'),
            'exportUrl' => route('srt.export', ['uploadedFile' => '__ID__']),
            'pronunciationTerms' => $pronunciationTerms,
            'ffmpegAvailable' => $ffmpegAvailable,
        ];
    @endphp

    <div x-data="generationWizard(@js($config))" class="space-y-6">
        {{-- Stepper --}}
        <nav aria-label="Progress" class="card p-4">
            <ol class="flex items-center gap-2 overflow-x-auto">
                <template x-for="(label, i) in steps" :key="i">
                    <li class="flex shrink-0 items-center gap-2">
                        <span class="flex h-8 w-8 items-center justify-center rounded-full text-sm font-semibold"
                              :class="step > i + 1 ? 'bg-emerald-500 text-white' : (step === i + 1 ? 'bg-brand-600 text-white ring-4 ring-brand-100' : 'bg-slate-100 text-slate-500')"
                              x-text="step > i + 1 ? '✓' : i + 1"></span>
                        <span class="text-sm font-medium" :class="step === i + 1 ? 'text-slate-900' : 'text-slate-500'" x-text="label"></span>
                        <span x-show="i < steps.length - 1" class="mx-2 h-px w-6 bg-slate-200 sm:w-10"></span>
                    </li>
                </template>
            </ol>
        </nav>

        @if (! $ffmpegAvailable)
            <div class="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800" role="alert">
                <strong>FFmpeg is not installed.</strong> It is needed to merge the audio, so generation is disabled until it's installed:
                <code class="ml-1 rounded bg-white px-1.5 py-0.5 font-mono text-xs ring-1 ring-rose-200">sudo apt install ffmpeg</code>
                (or set <code>FFMPEG_BINARY</code>/<code>FFPROBE_BINARY</code> in .env). Reload this page afterwards.
            </div>
        @endif
        @if (! $workerOnline)
            <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                <strong>The background worker is not running.</strong> Generations will wait in the queue until you start it:
                <code class="ml-1 rounded bg-white px-1.5 py-0.5 font-mono text-xs ring-1 ring-amber-200">php artisan queue:work --timeout=3600 --tries=1</code>
                (or run everything with <code>composer start</code>).
            </div>
        @endif

        @if (! $hasApiKey)
            <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                <strong>HeyGen is not connected.</strong> You can upload and review a script, but generation needs an API key — <a class="font-medium underline" href="{{ route('settings.api') }}">add it in API Settings</a>.
            </div>
        @endif

        {{-- STEP 1: Upload --}}
        <section x-show="step === 1" class="card p-6 sm:p-8" aria-labelledby="step1">
            <h2 id="step1" class="text-lg font-semibold text-slate-900">Upload your SRT subtitle file</h2>
            <p class="mt-1 text-sm text-slate-500">We'll extract the narration text. Timestamps, numbers and formatting tags are removed; your words and punctuation stay exactly as written.</p>

            <label for="srt-file"
                   class="mt-6 flex cursor-pointer flex-col items-center justify-center rounded-2xl border-2 border-dashed px-6 py-14 text-center transition"
                   :class="dragging ? 'border-brand-500 bg-brand-50' : 'border-slate-300 hover:border-brand-400 hover:bg-slate-50'"
                   @dragover.prevent="dragging = true" @dragleave.prevent="dragging = false" @drop.prevent="onDrop($event)">
                <template x-if="!uploading">
                    <div class="flex flex-col items-center">
                        <div class="mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-50 text-brand-600"><x-icon name="upload" class="h-7 w-7" /></div>
                        <p class="text-base font-medium text-slate-900">Drag &amp; drop your .srt file here</p>
                        <p class="mt-1 text-sm text-slate-500">or <span class="font-medium text-brand-600 underline">browse your computer</span></p>
                        <p class="mt-3 text-xs text-slate-400">SRT only · UTF-8 / Khmer supported · max <span x-text="Math.round({{ (int) $defaults['upload.max_kb'] }} / 1024 * 10) / 10"></span> MB</p>
                    </div>
                </template>
                <template x-if="uploading">
                    <div class="flex flex-col items-center gap-3 text-slate-600">
                        <svg class="h-8 w-8 animate-spin text-brand-600" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-20"/><path d="M22 12a10 10 0 00-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                        Reading subtitles…
                    </div>
                </template>
                <input id="srt-file" type="file" accept=".srt,application/x-subrip,text/plain" class="sr-only" @change="onPick($event)" :disabled="uploading">
            </label>
            <p x-show="uploadError" x-text="uploadError" role="alert" class="mt-4 rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-700"></p>
        </section>

        {{-- STEP 2: Review --}}
        <section x-show="step === 2" x-cloak class="space-y-6" aria-labelledby="step2">
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <div class="card p-4"><p class="text-xs text-slate-500">File</p><p class="truncate text-sm font-semibold text-slate-900" x-text="upload?.filename" :title="upload?.filename"></p><p class="text-xs text-slate-400" x-text="upload?.encoding"></p></div>
                <div class="card p-4"><p class="text-xs text-slate-500">Subtitles</p><p class="text-xl font-semibold text-slate-900" x-text="upload?.subtitle_count"></p></div>
                <div class="card p-4"><p class="text-xs text-slate-500">Words</p><p class="text-xl font-semibold text-slate-900" x-text="wordCount.toLocaleString()"></p><p class="text-xs text-slate-400"><span x-text="charCount.toLocaleString()"></span> characters</p></div>
                <div class="card p-4"><p class="text-xs text-slate-500">Estimated narration</p><p class="text-xl font-semibold text-slate-900" x-text="'≈ ' + fmt(estimatedSeconds)"></p><p class="text-xs text-slate-400" x-text="'Subtitles span ' + fmt(((upload?.last_end_ms ?? 0) - (upload?.first_start_ms ?? 0)) / 1000)"></p></div>
            </div>

            <div class="card p-6">
                <h2 id="step2" class="text-lg font-semibold text-slate-900">Generation mode</h2>
                <div class="mt-4 grid gap-3 md:grid-cols-2">
                    <button type="button" @click="setMode('continuous')" class="rounded-xl border-2 p-4 text-left transition" :class="mode === 'continuous' ? 'border-brand-500 bg-brand-50/50' : 'border-slate-200 hover:border-slate-300'" :aria-pressed="mode === 'continuous'">
                        <p class="font-semibold text-slate-900">Continuous narration <span class="badge ml-1 bg-brand-100 text-brand-700 ring-brand-200">Default</span></p>
                        <p class="mt-1 text-sm text-slate-500">One continuous script, generated in ordered chunks and merged into a single file. Best for storytelling videos.</p>
                    </button>
                    <button type="button" @click="setMode('segments')" class="rounded-xl border-2 p-4 text-left transition" :class="mode === 'segments' ? 'border-brand-500 bg-brand-50/50' : 'border-slate-200 hover:border-slate-300'" :aria-pressed="mode === 'segments'">
                        <p class="font-semibold text-slate-900">Subtitle-synchronized segments</p>
                        <p class="mt-1 text-sm text-slate-500">One audio clip per subtitle group with its original timestamps, a timing report and a timeline preview.</p>
                    </button>
                </div>
                <p x-show="mode === 'segments'" class="mt-3 flex gap-2 rounded-lg bg-sky-50 px-4 py-3 text-sm text-sky-800">
                    <x-icon name="info" class="h-5 w-5 shrink-0" />
                    Subtitle timestamps don't guarantee the generated speech fits each time window. Clips are never sped up or stretched; the timing report shows the measured length of every clip compared to its subtitle window.
                </p>
            </div>

            {{-- Continuous: editable script --}}
            <div x-show="mode === 'continuous'" class="card p-6">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h3 class="text-base font-semibold text-slate-900">Narration script</h3>
                        <p class="text-sm text-slate-500">Review and edit before generating. Nothing is changed automatically.</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <span x-show="scriptModified" class="badge bg-amber-50 text-amber-700 ring-amber-200">Edited</span>
                        <button type="button" class="btn-ghost btn-sm" x-show="scriptModified" @click="resetScript()">Reset to extracted text</button>
                        <button type="button" class="btn-secondary btn-sm" @click="exportCorrectedSrt()" title="Your edited text on the original subtitle timings">
                            <x-icon name="subtitle" class="h-4 w-4" /> Download corrected SRT
                        </button>
                    </div>
                </div>
                <label for="script" class="sr-only">Narration script</label>
                <textarea id="script" x-model="script" rows="16" class="input mt-4 text-[15px] leading-7" spellcheck="true"></textarea>
                <p class="hint">Blank lines mark paragraph breaks (inserted where the subtitles pause for 2+ seconds). Chunks are split at sentence boundaries.
                    To give a character its own voice, start the line with their name, e.g. <code>Kiki: Where are we going?</code>
                    <span x-show="speakers.length" class="font-medium text-brand-700" x-text="'· ' + speakers.length + ' character(s) found — assign voices in the next step.'"></span></p>
            </div>

            {{-- Segments: editable per segment --}}
            <div x-show="mode === 'segments'" class="card p-6">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h3 class="text-base font-semibold text-slate-900">Segments <span class="text-sm font-normal text-slate-500" x-text="'(' + segments.length + ')'"></span></h3>
                        <p class="text-sm text-slate-500">Each segment becomes one audio clip. Edit the text if needed.</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <label for="strategy" class="text-sm text-slate-600">Group by</label>
                        <select id="strategy" x-model="segmentStrategy" @change="loadSegments()" class="input w-auto">
                            <option value="sentence">Sentences (merge subtitles until a sentence ends)</option>
                            <option value="subtitle">Individual subtitles</option>
                        </select>
                    </div>
                </div>
                <div x-show="segmentsLoading" class="py-8 text-center text-sm text-slate-500">Building segments…</div>
                <ol x-show="!segmentsLoading" class="mt-4 max-h-[32rem] space-y-2 overflow-y-auto pr-1">
                    <template x-for="(seg, i) in segments" :key="i">
                        <li class="flex gap-3 rounded-lg border border-slate-200 p-3">
                            <div class="w-28 shrink-0 font-mono text-[11px] leading-5 text-slate-500">
                                <p class="font-sans text-xs font-semibold text-slate-700" x-text="'#' + (i + 1)"></p>
                                <p x-text="timecode(seg.start_ms)"></p>
                                <p x-text="timecode(seg.end_ms)"></p>
                            </div>
                            <textarea x-model="seg.text" rows="2" class="input text-sm" :aria-label="'Segment ' + (i + 1) + ' text'"></textarea>
                        </li>
                    </template>
                </ol>
            </div>

            <div class="flex justify-between">
                <button type="button" class="btn-secondary" @click="step = 1"><x-icon name="chevron-left" class="h-4 w-4" /> Upload another file</button>
                <button type="button" class="btn-primary" :disabled="!canContinueReview()" @click="step = 3">Continue <x-icon name="chevron-right" class="h-4 w-4" /></button>
            </div>
        </section>

        {{-- STEP 3: Configure voice --}}
        <section x-show="step === 3" x-cloak class="space-y-6" aria-labelledby="step3">
            <div class="card p-6">
                <h2 id="step3" class="text-lg font-semibold text-slate-900">Voice &amp; output</h2>

                @if ($voices->isEmpty())
                    <div class="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                        No voices loaded yet. <a href="{{ route('settings.voices') }}" class="font-medium underline">Load your HeyGen voices</a> to select <em>kikitoons</em>.
                    </div>
                @endif

                <div class="mt-5 grid gap-5 md:grid-cols-2">
                    <div class="md:col-span-2">
                        <label for="project_name" class="label">Project name</label>
                        <input id="project_name" x-model="form.project_name" maxlength="150" class="input" required>
                    </div>

                    <div>
                        <label for="voice" class="label">Voice</label>
                        <select id="voice" x-model.number="form.voice_profile_id" class="input">
                            <option value="">— Choose a voice —</option>
                            <template x-for="v in voices" :key="v.id">
                                <option :value="v.id" :disabled="!v.active" :selected="v.id === form.voice_profile_id"
                                        x-text="v.name + (v.is_default ? ' (default)' : '') + (v.mode ? ' · ' + v.mode : '') + (!v.active ? ' · ' + v.status : '')"></option>
                            </template>
                        </select>
                        <p class="hint">Voices come from your HeyGen workspace. New voices are never created automatically.</p>
                    </div>

                    <div>
                        <label for="language" class="label">Language</label>
                        <select id="language" x-model="form.language" class="input">
                            <option value="auto">Auto-detect</option>
                            @foreach (config('soundex.languages') as $code => $name)
                                <option value="{{ $code }}">{{ $name }} ({{ $code }})</option>
                            @endforeach
                        </select>
                        <p class="hint" x-show="containsKhmer">Khmer isn't in HeyGen's supported language list; auto-detect is used and results may vary.</p>
                    </div>

                    <div>
                        <label for="model" class="label">HeyGen model</label>
                        <select id="model" x-model="form.model" class="input">
                            @foreach (config('soundex.models') as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <p class="hint text-rose-600" x-show="turboWithProfessional">Professional voice clones only work with "HeyGen Voice 1".</p>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label for="format" class="label">Output format</label>
                            <select id="format" x-model="form.output_format" class="input">
                                @foreach (config('soundex.output_formats') as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div x-show="form.output_format === 'mp3'">
                            <label for="bitrate" class="label">MP3 quality</label>
                            <select id="bitrate" x-model.number="form.mp3_bitrate" class="input">
                                @foreach (config('soundex.mp3_bitrates') as $b)
                                    <option value="{{ $b }}">{{ $b / 1000 }} kbps</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div x-show="anyProfessional(selectedVoice)" class="md:col-span-2">
                        <label for="speed" class="label">Speed <span class="font-normal text-slate-500" x-text="'(' + Number(form.speed).toFixed(2) + '×)'"></span></label>
                        <input id="speed" type="range" min="0.75" max="1.25" step="0.05" x-model.number="form.speed" class="w-full accent-brand-600">
                        <p class="hint">Supported by HeyGen for professional voice clones only (0.75–1.25).</p>
                    </div>
                    <p x-show="selectedVoice && !anyProfessional(selectedVoice)" class="hint md:col-span-2">Speed control isn't offered: HeyGen ignores speed for instant voice clones.</p>
                </div>
            </div>

            @include('partials.voice-extras')

            <div class="card p-6">
                <h3 class="text-base font-semibold text-slate-900">Audio processing</h3>
                <div class="mt-4 grid gap-5 md:grid-cols-3">
                    <div x-show="mode === 'continuous'">
                        <label for="silence" class="label">Pause between chunks (ms)</label>
                        <input id="silence" type="number" min="0" max="5000" step="50" x-model.number="form.silence_between_ms" class="input">
                    </div>
                    <label class="flex items-start gap-3 rounded-lg border border-slate-200 p-3">
                        <input type="checkbox" x-model="form.normalize_loudness" class="mt-0.5 rounded border-slate-300 text-brand-600">
                        <span><span class="block text-sm font-medium text-slate-800">Normalize loudness</span><span class="text-xs text-slate-500">EBU R128, −16 LUFS for spoken word</span></span>
                    </label>
                    <label class="flex items-start gap-3 rounded-lg border border-slate-200 p-3" x-show="mode === 'continuous'">
                        <input type="checkbox" x-model="form.trim_chunk_silence" class="mt-0.5 rounded border-slate-300 text-brand-600">
                        <span><span class="block text-sm font-medium text-slate-800">Trim silence at chunk edges</span><span class="text-xs text-slate-500">Removes dead air before adding the pause</span></span>
                    </label>
                </div>
            </div>

            <div class="card flex flex-col gap-4 p-6 sm:flex-row sm:items-center">
                <div class="flex-1 text-sm text-slate-600">
                    <p><strong class="text-slate-900" x-text="selectedVoice ? selectedVoice.name : 'No voice selected'"></strong> · <span x-text="mode === 'continuous' ? 'Continuous narration' : 'Synchronized segments'"></span></p>
                    <p>About <span x-text="estimatedChunks"></span> HeyGen request(s) · ≈ <span x-text="fmt(estimatedSeconds)"></span> of audio</p>
                </div>
                <div class="flex gap-2">
                    <button type="button" class="btn-secondary" @click="step = 2"><x-icon name="chevron-left" class="h-4 w-4" /> Back</button>
                    <button type="button" class="btn-primary" @click="generate()" :disabled="submitting || blockReason !== null">
                        <svg x-show="submitting" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-30"/><path d="M22 12a10 10 0 00-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                        <x-icon name="sparkles" class="h-4 w-4" x-show="!submitting" /> Generate narration
                    </button>
                </div>
            </div>
            <p x-show="!submitting && blockReason" x-text="blockReason" class="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800"></p>
            <p x-show="submitError" x-text="submitError" role="alert" class="rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-700"></p>
        </section>

        {{-- STEP 4 & 5: Progress, preview and download --}}
        <template x-if="generation">
            <section x-show="step >= 4" class="space-y-6"
                     x-data="generationStatus(generation.status_url)" @generation-updated="onGenerationUpdate($event.detail)">
                <div class="card p-6" x-show="step === 4 || (s && s.status !== 'completed')">
                    <div class="mb-5 flex items-center justify-between">
                        <h2 class="text-lg font-semibold text-slate-900">Generating narration</h2>
                        <button type="button" class="btn-secondary btn-sm" x-show="s && ['pending','processing'].includes(s.status) && !s.cancel_requested"
                                @click="$store.confirm.ask({ title: 'Cancel generation?', message: 'Chunks already generated are kept, so you can resume later.', confirmLabel: 'Cancel generation', danger: true }, () => cancel('{{ url('/generations') }}/' + generation.id + '/cancel'))">
                            <x-icon name="stop" class="h-4 w-4" /> Cancel
                        </button>
                    </div>
                    @include('partials.progress')
                    <div class="mt-5 flex flex-wrap gap-2" x-show="s && ['failed','cancelled'].includes(s.status)">
                        <a :href="generation.show_url" class="btn-primary">Open details &amp; retry</a>
                        <button type="button" class="btn-secondary" @click="startOver()">Start over</button>
                    </div>
                    <p class="mt-4 text-xs text-slate-500" x-show="s && ['pending','processing'].includes(s.status)">You can leave this page — generation continues in the background and appears in your library.</p>
                </div>

                <div class="card p-6" x-show="step === 5 && s && s.status === 'completed'">
                    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h2 class="text-lg font-semibold text-slate-900">Your narration is ready 🎉</h2>
                            <p class="text-sm text-slate-500">Duration <span class="font-medium text-slate-700" x-text="fmt(s?.duration_seconds)"></span></p>
                        </div>
                        <span class="badge bg-emerald-50 text-emerald-700 ring-emerald-200">Completed</span>
                    </div>
                    <template x-if="s && s.stream_url">
                        <div x-data="waveformPlayer(s.stream_url, { duration: s.duration_seconds })" class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                            <audio x-ref="audio" :src="s.stream_url" preload="metadata"></audio>
                            <div class="flex items-center gap-3">
                                <button type="button" @click="toggle()" class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-brand-600 text-white shadow hover:bg-brand-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2" :aria-label="playing ? 'Pause' : 'Play'">
                                    <x-icon name="play" class="ml-0.5 h-5 w-5" x-show="!playing" /><x-icon name="pause" class="h-5 w-5" x-show="playing" x-cloak />
                                </button>
                                <div class="min-w-0 flex-1">
                                    <canvas x-ref="canvas" class="h-12 w-full cursor-pointer" @click="seek($event)" tabindex="0" @keydown="seekKey($event)" role="slider" aria-label="Seek audio" :aria-valuenow="Math.round(current)" :aria-valuemax="Math.round(duration)"></canvas>
                                </div>
                                <span class="w-24 shrink-0 text-right font-mono text-xs tabular-nums text-slate-500" x-text="timeLabel"></span>
                            </div>
                        </div>
                    </template>
                    <div class="mt-5 flex flex-wrap gap-2">
                        <a :href="s?.download_url" class="btn-primary"><x-icon name="download" class="h-4 w-4" /> Download <span x-text="form.output_format.toUpperCase()"></span></a>
                        <a :href="s?.voice_download_url" x-show="s?.voice_download_url" class="btn-secondary"><x-icon name="download" class="h-4 w-4" /> Voice only</a>
                        <a :href="s?.bundle_url" x-show="s?.bundle_url" class="btn-secondary"><x-icon name="download" class="h-4 w-4" /> Clips + timing report (ZIP)</a>
                        <a :href="s?.show_url" class="btn-secondary"><x-icon name="eye" class="h-4 w-4" /> View details</a>
                        <a href="{{ route('library') }}" class="btn-ghost">Open library</a>
                        <button type="button" class="btn-ghost" @click="startOver()"><x-icon name="plus" class="h-4 w-4" /> New narration</button>
                    </div>
                </div>
            </section>
        </template>
    </div>
</x-layouts.app>
