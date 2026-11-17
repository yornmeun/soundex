@use('App\Support\Format')
<div class="overflow-x-auto">
    <table class="min-w-full divide-y divide-slate-100 text-sm">
        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
            <tr>
                <th scope="col" class="px-5 py-3">Project Name</th>
                <th scope="col" class="hidden px-5 py-3 md:table-cell">Original SRT</th>
                <th scope="col" class="hidden px-5 py-3 lg:table-cell">Voice</th>
                <th scope="col" class="px-5 py-3">Duration</th>
                <th scope="col" class="px-5 py-3">Status</th>
                <th scope="col" class="hidden px-5 py-3 sm:table-cell">Created At</th>
                <th scope="col" class="px-5 py-3 text-right">Actions</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @foreach ($generations as $g)
                <tr class="hover:bg-slate-50/60">
                    <td class="max-w-[14rem] px-5 py-3">
                        <a href="{{ route('generations.show', $g) }}" class="block truncate font-medium text-slate-900 hover:text-brand-700">{{ $g->project_name }}</a>
                        <span class="text-xs text-slate-500">{{ $g->mode->label() }}</span>
                    </td>
                    <td class="hidden max-w-[12rem] truncate px-5 py-3 text-slate-600 md:table-cell">{{ $g->original_filename ?? '—' }}</td>
                    <td class="hidden px-5 py-3 text-slate-600 lg:table-cell">{{ $g->voice_name }}</td>
                    <td class="px-5 py-3 font-mono text-xs tabular-nums text-slate-600">{{ Format::duration($g->duration_seconds) }}</td>
                    <td class="px-5 py-3"><x-status-badge :status="$g->status" /></td>
                    <td class="hidden whitespace-nowrap px-5 py-3 text-slate-500 sm:table-cell" title="{{ $g->created_at }}">{{ $g->created_at->diffForHumans() }}</td>
                    <td class="px-5 py-3"><x-row-actions :generation="$g" /></td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
