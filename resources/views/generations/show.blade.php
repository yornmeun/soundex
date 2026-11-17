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
                <div class="card p-6">
                    <h2 class="mb-4 text-base font-semibold text-slate-900">{{ $g->mode->value === 'segments' ? 'Timeline preview' : 'Narration' }}</h2>
                    <x-audio-player :src="route('generations.stream', $g)" :download-url="route('generations.download', $g)" :duration="$g->duration_seconds" />
                    <div class="mt-4 flex flex-wrap gap-2">
                        <a href="{{ route('generations.download', $g) }}" class="btn-primary"><x-icon name="download" class="h-4 w-4" /> Download {{ strtoupper($g->output_format) }}</a>
                        @if ($g->bundle_path)
                            <a href="{{ route('generations.bundle', $g) }}" class="btn-secondary"><x-icon name="download" class="h-4 w-4" /> Clips + timing report (ZIP)</a>
                        @endif
                    </div>
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
                        'Language' => $g->language ? (config('kikitoons.languages')[$g->language] ?? $g->language) : 'Auto-detect',
                        'Model' => $g->model,
                        'Output' => strtoupper($g->output_format).($g->mp3_bitrate ? ' · '.($g->mp3_bitrate / 1000).' kbps' : ''),
                        'Speed' => $g->speed ? number_format($g->speed, 2).'×' : 'Default',
                        'Words' => number_format($g->total_words),
                        'Chunks' => $g->completed_chunks.' / '.$g->total_chunks,
                        'Duration' => Format::duration($g->duration_seconds),
                        'Loudness normalized' => $g->normalize_loudness ? 'Yes' : 'No',
                        'Pause between chunks' => $g->silence_between_ms.' ms',
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
