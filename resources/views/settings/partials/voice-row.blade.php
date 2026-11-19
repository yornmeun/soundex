@use('App\Services\SettingsService')
<li class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center" x-data="{ playing: false }">
    <div class="flex min-w-0 flex-1 items-center gap-3">
        <div @class(['flex h-10 w-10 shrink-0 items-center justify-center rounded-full', 'bg-brand-100 text-brand-700' => $voice->is_default, 'bg-slate-100 text-slate-500' => ! $voice->is_default])>
            <x-icon name="mic" class="h-5 w-5" />
        </div>
        <div class="min-w-0">
            <p class="flex flex-wrap items-center gap-2 font-medium text-slate-900">
                {{ $voice->name }}
                @if ($voice->is_default)<span class="badge bg-brand-50 text-brand-700 ring-brand-200">Default</span>@endif
                @if ($voice->isActive())
                    <span class="badge bg-emerald-50 text-emerald-700 ring-emerald-200">Active</span>
                @elseif (! $voice->is_available)
                    <span class="badge bg-slate-100 text-slate-600 ring-slate-200">Not found in {{ $voice->providerName() }}</span>
                @else
                    <span class="badge bg-amber-50 text-amber-700 ring-amber-200">{{ $voice->status }}</span>
                @endif
            </p>
            <p class="text-xs text-slate-500">
                @if ($voice->isAzure())
                    Microsoft neural voice · {{ $voice->language }}@if ($voice->gender) · {{ $voice->gender }}@endif
                    · <span class="font-mono">{{ $voice->voice_id }}</span>
                @else
                    {{ ucfirst($voice->mode ?? 'voice') }} clone
                    @if ($voice->language) · {{ config('soundex.languages')[$voice->language] ?? $voice->language }} @endif
                    · ID <span class="font-mono">{{ SettingsService::mask($voice->voice_id) }}</span>
                @endif
                @if ($voice->last_synced_at) · synced {{ $voice->last_synced_at->diffForHumans() }} @endif
            </p>
        </div>
    </div>
    <div class="flex items-center gap-2">
        @if ($voice->isActive())
            <audio x-ref="p" preload="none" src="{{ route('settings.voices.preview', $voice) }}" @play="playing = true" @pause="playing = false" @ended="playing = false" x-on:error="playing = false; $store.toasts.push('No preview available for this voice.', 'warning')"></audio>
            <button type="button" class="btn-secondary btn-sm" @click="$refs.p.paused ? $refs.p.play() : $refs.p.pause()">
                <x-icon name="play" class="h-4 w-4" x-show="!playing" /><x-icon name="pause" class="h-4 w-4" x-show="playing" x-cloak /> Preview
            </button>
            @unless ($voice->is_default)
                <form method="POST" action="{{ route('settings.voices.default', $voice) }}">@csrf<button class="btn-secondary btn-sm">Set as default</button></form>
            @endunless
        @endif
    </div>
</li>
