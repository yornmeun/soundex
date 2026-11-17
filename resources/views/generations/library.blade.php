@use('App\Support\Format')
<x-layouts.app title="My Audio Library">
    @include('partials.filters')

    @if ($generations->isEmpty())
        <div class="card">
            <x-empty-state icon="library" title="{{ $filters['q'] || $filters['status'] || $filters['folder'] ? 'No matching narrations' : 'Your library is empty' }}"
                message="{{ $filters['q'] || $filters['status'] || $filters['folder'] ? 'Try a different search or filter.' : 'Completed narrations appear here, ready to play and download.' }}">
                @unless ($filters['q'] || $filters['status'] || $filters['folder'])
                    <a href="{{ route('generations.create') }}" class="btn-primary"><x-icon name="plus" class="h-4 w-4" /> New Generation</a>
                @endunless
            </x-empty-state>
        </div>
    @else
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($generations as $g)
                <article class="card flex flex-col p-5">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <a href="{{ route('generations.show', $g) }}" class="block truncate font-semibold text-slate-900 hover:text-brand-700">{{ $g->project_name }}</a>
                            <p class="truncate text-xs text-slate-500">{{ $g->original_filename ?? 'No SRT' }} · {{ $g->voice_name }}</p>
                            @if ($g->folder)
                                <a href="{{ route('library', ['folder' => $g->folder_id]) }}" class="mt-1 inline-flex items-center gap-1 text-xs text-slate-500 hover:text-brand-700"><span class="h-2 w-2 rounded-full {{ $g->folder->dotClass() }}"></span>{{ $g->folder->name }}</a>
                            @endif
                        </div>
                        <x-status-badge :status="$g->status" />
                    </div>
                    <div class="mt-4 flex-1">
                        @if ($g->hasAudio())
                            <x-audio-player :src="route('generations.stream', $g)" :duration="$g->duration_seconds" lazy compact />
                        @elseif ($g->status->isActive())
                            <div class="rounded-xl bg-sky-50 p-3 text-sm text-sky-700">Generating… {{ $g->progress }}%</div>
                        @elseif ($g->error_message)
                            <p class="line-clamp-2 rounded-xl bg-rose-50 p-3 text-xs text-rose-700">{{ $g->error_message }}</p>
                        @endif
                    </div>
                    <div class="mt-4 flex items-center justify-between border-t border-slate-100 pt-3 text-xs text-slate-500">
                        <span>{{ Format::duration($g->duration_seconds) }} · {{ number_format($g->total_words) }} words · {{ $g->created_at->format('M j, Y') }}</span>
                        <x-row-actions :generation="$g" :play="false" />
                    </div>
                </article>
            @endforeach
        </div>
        <div class="mt-6">{{ $generations->links() }}</div>
    @endif
</x-layouts.app>
