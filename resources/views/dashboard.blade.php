@use('App\Support\Format')
<x-layouts.app title="Dashboard">
    @if (! $hasApiKey)
        <div class="mb-6 flex flex-col gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-4 sm:flex-row sm:items-center">
            <x-icon name="warning" class="h-6 w-6 shrink-0 text-amber-600" />
            <p class="flex-1 text-sm text-amber-800"><strong>Connect HeyGen to get started.</strong> Add your HeyGen API key, then load your voices to select <em>kikitoons</em>.</p>
            <a href="{{ route('settings.api') }}" class="btn-secondary btn-sm">Open API Settings</a>
        </div>
    @elseif (! $defaultVoice)
        <div class="mb-6 flex flex-col gap-3 rounded-2xl border border-sky-200 bg-sky-50 p-4 sm:flex-row sm:items-center">
            <x-icon name="mic" class="h-6 w-6 shrink-0 text-sky-600" />
            <p class="flex-1 text-sm text-sky-800">No default voice selected yet. Load your HeyGen voices and choose your <em>kikitoons</em> voice.</p>
            <a href="{{ route('settings.voices') }}" class="btn-secondary btn-sm">Voice Settings</a>
        </div>
    @endif

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat-card label="Total Generations" :value="number_format($stats['total'])" icon="sparkles" tone="brand" />
        <x-stat-card label="Total Audio Generated" :value="Format::humanDuration($stats['seconds'])" icon="wave" tone="sky" />
        <x-stat-card label="Successful Generations" :value="number_format($stats['completed'])" icon="check" tone="emerald" />
        <x-stat-card label="Failed Generations" :value="number_format($stats['failed'])" icon="warning" tone="rose" />
    </div>

    <div class="card mt-8 overflow-hidden">
        <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
            <div>
                <h2 class="text-base font-semibold text-slate-900">Recent generations</h2>
                <p class="text-sm text-slate-500">Your latest narration projects.</p>
            </div>
            <a href="{{ route('history') }}" class="text-sm font-medium text-brand-600 hover:underline">View all</a>
        </div>

        @if ($recent->isEmpty())
            <x-empty-state title="No generations yet" message="Upload an SRT subtitle file and turn it into a narration with your kikitoons voice.">
                <a href="{{ route('generations.create') }}" class="btn-primary"><x-icon name="plus" class="h-4 w-4" /> Create your first narration</a>
            </x-empty-state>
        @else
            @include('partials.generations-table', ['generations' => $recent])
        @endif
    </div>
</x-layouts.app>
