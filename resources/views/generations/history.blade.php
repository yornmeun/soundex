@use('App\Support\Format')
<x-layouts.app title="Generation History">
    @include('partials.filters')

    <div class="card overflow-hidden">
        @if ($generations->isEmpty())
            <x-empty-state icon="clock" title="No history yet" message="Every generation you start is tracked here with its timing and status." />
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-100 text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-5 py-3">Project</th>
                            <th class="px-5 py-3">Status</th>
                            <th class="hidden px-5 py-3 md:table-cell">Started</th>
                            <th class="hidden px-5 py-3 md:table-cell">Completed</th>
                            <th class="hidden px-5 py-3 lg:table-cell">Processing</th>
                            <th class="px-5 py-3">Chunks</th>
                            <th class="hidden px-5 py-3 xl:table-cell">Error</th>
                            <th class="px-5 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($generations as $g)
                            <tr class="align-top hover:bg-slate-50/60">
                                <td class="max-w-[14rem] px-5 py-3">
                                    <a href="{{ route('generations.show', $g) }}" class="block truncate font-medium text-slate-900 hover:text-brand-700">{{ $g->project_name }}</a>
                                    <span class="text-xs text-slate-500">#{{ $g->id }} · {{ $g->voice_name }}</span>
                                </td>
                                <td class="px-5 py-3">
                                    <x-status-badge :status="$g->status" />
                                    @if ($g->status->isActive())<p class="mt-1 text-xs text-slate-500">{{ $g->stage?->label() }} · {{ $g->progress }}%</p>@endif
                                </td>
                                <td class="hidden whitespace-nowrap px-5 py-3 text-slate-600 md:table-cell">{{ $g->started_at?->format('M j, H:i:s') ?? '—' }}</td>
                                <td class="hidden whitespace-nowrap px-5 py-3 text-slate-600 md:table-cell">{{ $g->completed_at?->format('M j, H:i:s') ?? '—' }}</td>
                                <td class="hidden px-5 py-3 text-slate-600 lg:table-cell">{{ $g->processingSeconds() !== null ? Format::humanDuration($g->processingSeconds()) : '—' }}</td>
                                <td class="px-5 py-3 tabular-nums text-slate-600">{{ $g->completed_chunks }}/{{ $g->total_chunks }}</td>
                                <td class="hidden max-w-xs px-5 py-3 xl:table-cell"><p class="line-clamp-2 text-xs text-rose-600" title="{{ $g->error_message }}">{{ $g->status->value === 'completed' ? '' : $g->error_message }}</p></td>
                                <td class="px-5 py-3">
                                    <div class="flex items-center justify-end gap-1">
                                        @if (in_array($g->status->value, ['failed', 'cancelled']))
                                            <x-confirm-form :action="route('generations.retry', $g)" title="Retry failed chunks?" message="Only chunks that did not complete are sent to HeyGen again." confirm-label="Retry">
                                                <button class="btn-secondary btn-sm">Retry</button>
                                            </x-confirm-form>
                                        @endif
                                        <x-row-actions :generation="$g" />
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="border-t border-slate-100 px-5 py-3">{{ $generations->links() }}</div>
        @endif
    </div>
</x-layouts.app>
