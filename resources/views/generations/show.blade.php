@use('App\Support\Format')
@php($g = $generation)
<x-layouts.app :title="$g->project_name">
    <div class="mb-6 flex flex-wrap items-center gap-3">
        <a href="{{ route('history') }}" class="btn-ghost btn-sm"><x-icon name="chevron-left" class="h-4 w-4" /> History</a>
        <x-status-badge :status="$g->status" />
        <span class="text-sm text-slate-500">{{ $g->mode->label() }}</span>
        @if ($g->regeneratedFrom)
            <span class="text-sm text-slate-500">· regenerated from <a class="text-brand-600 hover:underline" href="{{ route('generations.show', $g->regeneratedFrom) }}">#{{ $g->regenerated_from_id }}</a></span>
        @endif
        @if ($g->batch)
            <a href="{{ route('batches.show', $g->batch) }}" class="badge bg-slate-100 text-slate-700 ring-slate-200"><x-icon name="stack" class="h-3.5 w-3.5" /> {{ $g->batch->name }}</a>
        @endif
        <form method="POST" action="{{ route('generations.folder', $g) }}" class="flex items-center gap-1.5">
            @csrf @method('PATCH')
            <x-icon name="folder" class="h-4 w-4 text-slate-400" />
            <label for="move-folder" class="sr-only">Folder</label>
            <select id="move-folder" name="folder_id" onchange="this.form.submit()" class="input w-auto py-1 text-xs">
                <option value="">No folder</option>
                @foreach ($folders as $folder)
                    <option value="{{ $folder->id }}" @selected($g->folder_id === $folder->id)>{{ $folder->name }}</option>
                @endforeach
            </select>
        </form>
        <div class="ml-auto flex flex-wrap gap-2">
            @if (in_array($g->status->value, ['failed', 'cancelled']))
                <x-confirm-form :action="route('generations.retry', $g)" title="Retry failed chunks?"
                    message="Only chunks that did not complete will be sent to HeyGen again. Completed chunks are reused." confirm-label="Retry">
                    <button class="btn-primary btn-sm"><x-icon name="refresh" class="h-4 w-4" /> Retry failed chunks</button>
                </x-confirm-form>
            @endif
            @if ($g->status->isActive())
                <form method="POST" action="{{ route('generations.cancel', $g) }}">@csrf<button class="btn-secondary btn-sm"><x-icon name="stop" class="h-4 w-4" /> Cancel</button></form>
            @else
                <x-confirm-form :action="route('generations.regenerate', $g)" title="Regenerate narration?" message="A new generation is created with the same script and settings. This uses HeyGen credits." confirm-label="Regenerate">
                    <button class="btn-secondary btn-sm"><x-icon name="refresh" class="h-4 w-4" /> Regenerate</button>
                </x-confirm-form>
                <x-confirm-form :action="route('generations.destroy', $g)" method="DELETE" title="Delete generation?" message="This permanently deletes the generation and its audio files." confirm-label="Delete" :danger="true">
                    <button class="btn-secondary btn-sm text-rose-600"><x-icon name="trash" class="h-4 w-4" /> Delete</button>
                </x-confirm-form>
            @endif
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            @if ($g->status->isActive())
                <div class="card p-6" x-data="generationStatus(@js(route('generations.status', $g)))" @generation-updated="if (['completed','failed','cancelled'].includes($event.detail.status)) setTimeout(() => location.reload(), 800)">
                    <h2 class="mb-5 text-base font-semibold text-slate-900">Progress</h2>
                    @include('partials.progress')
                </div>
            @endif

            @if ($g->hasAudio())
                <div class="card p-6" data-main-player>
                    <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                        <h2 class="text-base font-semibold text-slate-900">{{ $g->mode->value === 'segments' ? 'Timeline preview' : 'Narration' }}</h2>
                        @if ($g->hasMix())
                            <span class="badge bg-brand-50 text-brand-700 ring-brand-200"><x-icon name="music" class="h-3.5 w-3.5" /> With music / effects</span>
                        @endif
                    </div>
                    @if ($g->isMixing())
                        <div class="mb-4 flex items-center gap-2 rounded-lg bg-sky-50 px-4 py-3 text-sm text-sky-800"
                             x-data x-init="const t = setInterval(async () => { const r = await api(@js(route('generations.status', $g))); if (!['pending','processing'].includes(r.mix_status)) { clearInterval(t); location.reload(); } }, 2500)">
                            <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-20"/><path d="M22 12a10 10 0 00-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                            Mixing music and sound effects… The current audio stays available until it's done.
                        </div>
                    @elseif ($g->mix_status === 'failed')
                        <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800"><strong>Mixing failed.</strong> {{ $g->mix_error }}</div>
                    @endif
                    <x-audio-player :src="route('generations.stream', $g).'?v='.$g->updated_at?->timestamp" :download-url="route('generations.download', $g)" :duration="$g->duration_seconds" />
                    <div class="mt-4 flex flex-wrap gap-2">
                        <a href="{{ route('generations.download', $g) }}" class="btn-primary"><x-icon name="download" class="h-4 w-4" /> Download {{ strtoupper($g->output_format) }}</a>
                        @if ($g->hasMix())
                            <a href="{{ route('generations.download', [$g, 'voice' => 1]) }}" class="btn-secondary"><x-icon name="download" class="h-4 w-4" /> Voice only</a>
                        @endif
                        @if ($g->bundle_path)
                            <a href="{{ route('generations.bundle', $g) }}" class="btn-secondary"><x-icon name="download" class="h-4 w-4" /> Clips + timing report (ZIP)</a>
                        @endif
                    </div>
                </div>

                {{-- Subtitles --}}
                <div class="card p-6">
                    <h2 class="text-base font-semibold text-slate-900">Subtitles</h2>
                    <div class="mt-4 grid gap-3 sm:grid-cols-2">
                        <div class="rounded-xl border border-slate-200 p-4">
                            <p class="text-sm font-medium text-slate-900">Corrected SRT</p>
                            <p class="mt-1 text-xs text-slate-500">Your edited text on the <em>original</em> subtitle timings.</p>
                            @if ($g->mode->value === 'segments' || ($g->uploadedFile && $g->uploadedFile->cues))
                                <a href="{{ route('generations.srt', [$g, 'type' => 'corrected']) }}" class="btn-secondary btn-sm mt-3"><x-icon name="subtitle" class="h-4 w-4" /> Download</a>
                            @else
                                <p class="mt-3 text-xs text-slate-400">The original SRT is no longer available.</p>
                            @endif
                        </div>
                        <div class="rounded-xl border border-slate-200 p-4">
                            <p class="text-sm font-medium text-slate-900">Narration-timed SRT</p>
                            <p class="mt-1 text-xs text-slate-500">Timed to <em>this audio</em>, from HeyGen's measured word timestamps.</p>
                            @if ($hasWordTimings)
                                <a href="{{ route('generations.srt', [$g, 'type' => 'narration']) }}" class="btn-secondary btn-sm mt-3"><x-icon name="subtitle" class="h-4 w-4" /> Download</a>
                            @else
                                <p class="mt-3 text-xs text-slate-400">{{ $g->trim_chunk_silence ? 'Not available when “trim silence at chunk edges” is on.' : 'Word timings are missing (generated before this feature). Regenerate to get them.' }}</p>
                            @endif
                        </div>
                    </div>
                </div>

                {{-- Music & sound effects --}}
                <div class="card p-6" x-data="mixEditor(@js(['mix' => $g->mix_settings, 'sfxAssets' => $sfxAssets, 'duration' => $g->duration_seconds]))">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div>
                            <h2 class="text-base font-semibold text-slate-900">Music &amp; sound effects</h2>
                            <p class="text-sm text-slate-500">Re-mixing reuses the generated voice — <strong>no HeyGen credits</strong>.</p>
                        </div>
                        <a href="{{ route('sounds.index') }}" class="text-sm font-medium text-brand-600 hover:underline">Sound Library</a>
                    </div>

                    @if ($musicAssets->isEmpty() && $sfxAssets->isEmpty())
                        <p class="mt-4 rounded-lg bg-slate-50 p-4 text-sm text-slate-600">Upload music or sound effects in the <a class="text-brand-600 underline" href="{{ route('sounds.index') }}">Sound Library</a> first.</p>
                    @else
                        <form method="POST" action="{{ route('generations.mix', $g) }}" class="mt-5 space-y-6"
                              @submit.prevent="if (hasErrors) return; api($el.action, { method: 'POST', body: payload() }).then(r => { $store.toasts.push(r.message); setTimeout(() => location.reload(), 600); }).catch(e => $store.toasts.push(e.message, 'error'))">
                            <fieldset class="grid gap-4 sm:grid-cols-2">
                                <legend class="mb-2 text-sm font-semibold text-slate-800">Background music</legend>
                                <div class="sm:col-span-2">
                                    <label for="mix-music" class="sr-only">Music track</label>
                                    <select id="mix-music" x-model="musicAssetId" class="input">
                                        <option value="">No music</option>
                                        @foreach ($musicAssets as $track)
                                            <option value="{{ $track->id }}">{{ $track->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <template x-if="musicAssetId">
                                    <div class="grid gap-4 sm:col-span-2 sm:grid-cols-2">
                                        <label class="text-sm text-slate-700">Volume <span class="tabular-nums text-slate-500" x-text="musicVolumeDb + ' dB'"></span>
                                            <input type="range" min="-40" max="0" step="1" x-model.number="musicVolumeDb" class="mt-1 w-full accent-brand-600"></label>
                                        <label class="flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" x-model="musicDuck" class="rounded border-slate-300 text-brand-600"> Lower music while the voice speaks</label>
                                        <label class="text-sm text-slate-700">Fade in (s) <input type="number" min="0" max="15" step="0.5" x-model.number="musicFadeIn" class="input mt-1"></label>
                                        <label class="text-sm text-slate-700">Fade out (s) <input type="number" min="0" max="15" step="0.5" x-model.number="musicFadeOut" class="input mt-1"></label>
                                    </div>
                                </template>
                            </fieldset>

                            <fieldset>
                                <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                                    <legend class="text-sm font-semibold text-slate-800">Sound effects <span class="font-normal text-slate-500" x-text="'(' + sfx.length + ')'"></span></legend>
                                    @if ($sfxAssets->isNotEmpty())
                                        <button type="button" class="btn-secondary btn-sm" @click="addEffect()" title="Adds an effect at the player's current position"><x-icon name="plus" class="h-4 w-4" /> Add at current playback time</button>
                                    @endif
                                </div>
                                @if ($sfxAssets->isEmpty())
                                    <p class="text-xs text-slate-500">No sound effects uploaded.</p>
                                @endif
                                <ul class="space-y-2">
                                    <template x-for="e in sfx" :key="e.id">
                                        <li class="grid gap-2 rounded-lg border p-3 sm:grid-cols-[7rem_1fr_9rem_auto] sm:items-center" :class="invalid(e) ? 'border-rose-300 bg-rose-50' : 'border-slate-200'">
                                            <label class="text-xs text-slate-500">At (m:ss.ms)<input x-model="e.at" class="input mt-0.5 font-mono" placeholder="0:12.500"></label>
                                            <label class="text-xs text-slate-500">Sound
                                                <select x-model.number="e.asset_id" class="input mt-0.5">
                                                    <template x-for="a in sfxAssets" :key="a.id"><option :value="a.id" x-text="a.name" :selected="a.id === e.asset_id"></option></template>
                                                </select></label>
                                            <label class="text-xs text-slate-500">Volume <span x-text="e.volume_db + ' dB'"></span><input type="range" min="-30" max="10" step="1" x-model.number="e.volume_db" class="mt-2 w-full accent-brand-600"></label>
                                            <button type="button" class="icon-btn self-end" @click="removeEffect(e)" aria-label="Remove effect"><x-icon name="x" class="h-4 w-4" /></button>
                                            <p x-show="invalid(e)" class="text-xs text-rose-600 sm:col-span-4" x-text="'Use a time like 0:12.500 within the narration (' + fmt(duration) + ').'"></p>
                                        </li>
                                    </template>
                                </ul>
                            </fieldset>

                            <div class="flex justify-end">
                                <button class="btn-primary" :disabled="hasErrors" @disabled($g->isMixing())><x-icon name="music" class="h-4 w-4" /> Apply mix</button>
                            </div>
                        </form>
                    @endif
                </div>
            @endif

            @if (! $g->status->isActive() && $g->error_message)
                <div class="rounded-2xl border border-rose-200 bg-rose-50 p-5 text-sm text-rose-800">
                    <p class="font-semibold">{{ $g->status->value === 'cancelled' ? 'Cancelled' : 'Error' }}</p>
                    <p class="mt-1 break-words">{{ $g->error_message }}</p>
                </div>
            @endif

            @if ($g->timing_report)
                <div class="card overflow-hidden">
                    <div class="border-b border-slate-100 px-5 py-4">
                        <h2 class="text-base font-semibold text-slate-900">Timing report</h2>
                        <p class="text-sm text-slate-500">Measured clip length vs. the original subtitle window. Clips are not stretched; red rows are longer than their window.</p>
                    </div>
                    <div class="max-h-[28rem] overflow-auto">
                        <table class="min-w-full text-sm">
                            <thead class="sticky top-0 bg-slate-50 text-left text-xs font-semibold uppercase text-slate-500">
                                <tr><th class="px-4 py-2">#</th><th class="px-4 py-2">Start → End</th><th class="px-4 py-2">Window</th><th class="px-4 py-2">Audio</th><th class="px-4 py-2">Text</th></tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($g->timing_report as $row)
                                    <tr @class(['bg-rose-50/60' => ! $row['fits_subtitle_window']])>
                                        <td class="px-4 py-2 text-slate-500">{{ $row['segment'] }}</td>
                                        <td class="whitespace-nowrap px-4 py-2 font-mono text-xs">{{ $row['start'] }} → {{ $row['end'] }}</td>
                                        <td class="px-4 py-2 tabular-nums">{{ number_format($row['available_seconds'], 2) }}s</td>
                                        <td @class(['px-4 py-2 tabular-nums', 'font-semibold text-rose-700' => ! $row['fits_subtitle_window']])>{{ number_format($row['generated_seconds'], 2) }}s</td>
                                        <td class="max-w-md px-4 py-2 text-slate-700">{{ $row['text'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            <div class="card overflow-hidden">
                <div class="border-b border-slate-100 px-5 py-4">
                    <h2 class="text-base font-semibold text-slate-900">Chunks <span class="font-normal text-slate-500">({{ $g->chunks->count() }})</span></h2>
                    <p class="text-sm text-slate-500">Each chunk is one HeyGen request, generated in order.</p>
                </div>
                @if ($g->chunks->isEmpty())
                    <p class="px-5 py-8 text-center text-sm text-slate-500">The script will be split into chunks when processing starts.</p>
                @else
                    <ul class="max-h-[32rem] divide-y divide-slate-100 overflow-y-auto">
                        @foreach ($g->chunks as $chunk)
                            <li class="flex gap-4 px-5 py-3 text-sm">
                                <span class="w-8 shrink-0 font-semibold text-slate-400">#{{ $chunk->chunk_index + 1 }}</span>
                                <div class="min-w-0 flex-1">
                                    @if ($chunk->speaker)
                                        <span class="badge mb-1 bg-brand-50 text-brand-700 ring-brand-200"><x-icon name="users" class="h-3 w-3" /> {{ $chunk->speaker }} · {{ $chunk->voice_name }}</span>
                                    @endif
                                    <p class="line-clamp-2 text-slate-700">{{ $chunk->text }}</p>
                                    <p class="mt-1 text-xs text-slate-500">
                                        {{ number_format(mb_strlen($chunk->text)) }} chars
                                        @if ($chunk->duration_seconds) · {{ Format::duration($chunk->duration_seconds) }} @endif
                                        @if ($chunk->attempts > 1) · {{ $chunk->attempts }} attempts @endif
                                        @if ($chunk->start_ms !== null) · {{ \App\Services\Generation\GenerationProcessor::timecode($chunk->start_ms) }} @endif
                                    </p>
                                    @if ($chunk->error_message && $chunk->status->value !== 'completed')
                                        <p class="mt-1 text-xs text-rose-600">{{ $chunk->error_message }}</p>
                                    @endif
                                </div>
                                <span @class(['badge h-fit', match ($chunk->status->value) {
                                    'completed' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
                                    'failed' => 'bg-rose-50 text-rose-700 ring-rose-200',
                                    'processing' => 'bg-sky-50 text-sky-700 ring-sky-200',
                                    'cancelled' => 'bg-amber-50 text-amber-700 ring-amber-200',
                                    default => 'bg-slate-100 text-slate-600 ring-slate-200',
                                }])>{{ ucfirst($chunk->status->value) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <details class="card p-6">
                <summary class="cursor-pointer text-base font-semibold text-slate-900">Script</summary>
                <div class="mt-4 max-h-[30rem] overflow-y-auto whitespace-pre-wrap rounded-lg bg-slate-50 p-4 text-sm leading-7 text-slate-700">{{ $g->script }}</div>
            </details>
        </div>

        <aside class="space-y-6">
            <div class="card p-6">
                <h2 class="mb-4 text-base font-semibold text-slate-900">Details</h2>
                <dl class="space-y-3 text-sm">
                    @foreach ([
                        'Original file' => $g->original_filename ?? '—',
                        'Voice' => $g->voice_name.($g->voice_mode ? ' ('.$g->voice_mode.')' : ''),
                        'Language' => $g->language ? (config('soundex.languages')[$g->language] ?? $g->language) : 'Auto-detect',
                        'Model' => $g->model,
                        'Output' => strtoupper($g->output_format).($g->mp3_bitrate ? ' · '.($g->mp3_bitrate / 1000).' kbps' : ''),
                        'Speed' => $g->speed ? number_format($g->speed, 2).'×' : 'Default',
                        'Words' => number_format($g->total_words),
                        'Chunks' => $g->completed_chunks.' / '.$g->total_chunks,
                        'Duration' => Format::duration($g->duration_seconds),
                        'Loudness normalized' => $g->normalize_loudness ? 'Yes' : 'No',
                        'Pause between chunks' => $g->silence_between_ms.' ms',
                        'Folder' => $g->folder?->name ?? '—',
                        'Character voices' => $g->speaker_voices ? collect($g->speaker_voices)->map(fn ($v) => $v['label'].' → '.$v['voice_name'])->implode(', ') : '—',
                        'Pronunciations' => $g->use_pronunciations && $g->pronunciation_dictionary ? count($g->pronunciation_dictionary).' term(s)' : 'Off',
                    ] as $label => $value)
                        <div class="flex justify-between gap-4"><dt class="text-slate-500">{{ $label }}</dt><dd class="text-right font-medium text-slate-800">{{ $value }}</dd></div>
                    @endforeach
                </dl>
            </div>
            <div class="card p-6">
                <h2 class="mb-4 text-base font-semibold text-slate-900">Timeline</h2>
                <dl class="space-y-3 text-sm">
                    <div class="flex justify-between gap-4"><dt class="text-slate-500">Created</dt><dd class="text-right text-slate-800">{{ $g->created_at->format('M j, Y H:i:s') }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-slate-500">Started</dt><dd class="text-right text-slate-800">{{ $g->started_at?->format('M j, Y H:i:s') ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-slate-500">Finished</dt><dd class="text-right text-slate-800">{{ $g->completed_at?->format('M j, Y H:i:s') ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-slate-500">Processing time</dt><dd class="text-right text-slate-800">{{ $g->processingSeconds() !== null ? Format::humanDuration($g->processingSeconds()) : '—' }}</dd></div>
                </dl>
            </div>
        </aside>
    </div>
</x-layouts.app>
