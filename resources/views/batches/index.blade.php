<x-layouts.app title="Batches">
    <div class="mb-6 flex justify-end"><a href="{{ route('batches.create') }}" class="btn-primary"><x-icon name="plus" class="h-4 w-4" /> New batch</a></div>
    <div class="card overflow-hidden">
        @if ($batches->isEmpty())
            <x-empty-state icon="stack" title="No batches yet" message="Upload several SRT files at once and generate them with one click." />
        @else
            <ul class="divide-y divide-slate-100">
                @foreach ($batches as $batch)
                    @php($counts = $batch->statusCounts())
                    <li>
                        <a href="{{ route('batches.show', $batch) }}" class="flex flex-col gap-2 px-5 py-4 hover:bg-slate-50 sm:flex-row sm:items-center">
                            <div class="min-w-0 flex-1">
                                <p class="truncate font-medium text-slate-900">{{ $batch->name }}</p>
                                <p class="text-xs text-slate-500">{{ $batch->total_items }} files · {{ $batch->created_at->diffForHumans() }}</p>
                            </div>
                            <div class="flex flex-wrap gap-1.5 text-xs">
                                @foreach (\App\Enums\GenerationStatus::cases() as $status)
                                    @if ($counts[$status->value])
                                        <span class="badge {{ $status->badgeClasses() }}">{{ $counts[$status->value] }} {{ strtolower($status->label()) }}</span>
                                    @endif
                                @endforeach
                            </div>
                        </a>
                    </li>
                @endforeach
            </ul>
            <div class="border-t border-slate-100 px-5 py-3">{{ $batches->links() }}</div>
        @endif
    </div>
</x-layouts.app>
