<x-layouts.app :title="$batch->name">
    <div x-data="batchStatus(@js(route('batches.status', $batch)), null)" class="space-y-6">
        <div class="flex flex-wrap items-center gap-3">
            <a href="{{ route('batches.index') }}" class="btn-ghost btn-sm"><x-icon name="chevron-left" class="h-4 w-4" /> Batches</a>
            <span class="text-sm text-slate-500">{{ $batch->total_items }} narrations · created {{ $batch->created_at->diffForHumans() }}</span>
            @if ($batch->folder)
                <a href="{{ route('library', ['folder' => $batch->folder_id]) }}" class="badge bg-slate-100 text-slate-700 ring-slate-200"><x-icon name="folder" class="h-3.5 w-3.5" /> {{ $batch->folder->name }}</a>
            @endif
        </div>

        <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
            @foreach (['pending' => 'Queued', 'processing' => 'Generating', 'completed' => 'Completed', 'failed' => 'Failed'] as $key => $label)
                <div class="card p-4"><p class="text-xs text-slate-500">{{ $label }}</p><p class="text-2xl font-semibold text-slate-900" x-text="s ? s.counts['{{ $key }}'] : '…'"></p></div>
            @endforeach
        </div>

        <div x-show="s && !s.worker_online && s.counts.pending > 0" x-cloak class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
            The background worker is not running, so queued narrations can't start. Run <code>php artisan queue:work --timeout=3600 --tries=1</code>.
        </div>

        <div class="card overflow-hidden">
            <ul class="divide-y divide-slate-100">
                @foreach ($batch->generations as $g)
                    <li class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center">
                        <div class="min-w-0 flex-1">
                            <a href="{{ route('generations.show', $g) }}" class="block truncate font-medium text-slate-900 hover:text-brand-700">{{ $g->project_name }}</a>
                            <p class="truncate text-xs text-slate-500">{{ $g->original_filename }}</p>
                            <p class="mt-1 text-xs text-rose-600" x-show="item({{ $g->id }}).status === 'failed'" x-text="item({{ $g->id }}).error"></p>
                        </div>
                        <div class="w-full sm:w-56">
                            <div class="mb-1 flex justify-between text-xs text-slate-500">
                                <span x-text="item({{ $g->id }}).status === 'processing' ? item({{ $g->id }}).stage_label : (item({{ $g->id }}).status_label || '{{ $g->status->label() }}')"></span>
                                <span x-text="(item({{ $g->id }}).progress ?? {{ $g->progress }}) + '%'"></span>
                            </div>
                            <div class="h-2 overflow-hidden rounded-full bg-slate-100">
                                <div class="h-full rounded-full transition-all duration-700"
                                     :class="{ 'bg-emerald-500': item({{ $g->id }}).status === 'completed', 'bg-rose-500': item({{ $g->id }}).status === 'failed', 'bg-amber-500': item({{ $g->id }}).status === 'cancelled', 'bg-brand-600': ['pending','processing'].includes(item({{ $g->id }}).status) }"
                                     :style="`width: ${Math.max(2, item({{ $g->id }}).progress ?? {{ $g->progress }})}%`"></div>
                            </div>
                        </div>
                        <div class="flex items-center gap-1 sm:w-40 sm:justify-end">
                            <span class="mr-2 font-mono text-xs text-slate-500" x-text="item({{ $g->id }}).duration"></span>
                            <a x-show="item({{ $g->id }}).status === 'completed'" href="{{ route('generations.download', $g) }}" class="icon-btn" title="Download"><x-icon name="download" class="h-4 w-4" /></a>
                            <a href="{{ route('generations.show', $g) }}" class="icon-btn" title="Details"><x-icon name="eye" class="h-4 w-4" /></a>
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
</x-layouts.app>
