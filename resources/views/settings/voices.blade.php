@use('App\Services\SettingsService')
<x-layouts.app title="Voice Settings">
    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <div class="card overflow-hidden">
                <div class="flex flex-col gap-3 border-b border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 class="text-base font-semibold text-slate-900">HeyGen voices</h2>
                        <p class="text-sm text-slate-500">Voice clones in your HeyGen workspace. This app never creates or clones voices.</p>
                    </div>
                    <form method="POST" action="{{ route('settings.voices.refresh') }}" x-data="{ busy: false }" @submit="busy = true">
                        @csrf
                        <button class="btn-primary btn-sm" :disabled="busy" @disabled(! $hasApiKey)>
                            <x-icon name="refresh" class="h-4 w-4" x-bind:class="busy && 'animate-spin'" /> <span x-text="busy ? 'Loading…' : 'Refresh voice list'">Refresh voice list</span>
                        </button>
                    </form>
                </div>

                @if (! $hasApiKey)
                    <x-empty-state icon="key" title="Connect HeyGen first" message="Add your HeyGen API key to load your voices.">
                        <a href="{{ route('settings.api') }}" class="btn-primary">API Settings</a>
                    </x-empty-state>
                @elseif ($voices->isEmpty())
                    <x-empty-state icon="mic" title="No voices loaded" message="Click “Refresh voice list” to load the voices from your HeyGen workspace, including {{ $targetName }}." />
                @else
                    <ul class="divide-y divide-slate-100">
                        @foreach ($voices as $voice)
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
                                                <span class="badge bg-slate-100 text-slate-600 ring-slate-200">Not found in HeyGen</span>
                                            @else
                                                <span class="badge bg-amber-50 text-amber-700 ring-amber-200">{{ $voice->status }}</span>
                                            @endif
                                        </p>
                                        <p class="text-xs text-slate-500">
                                            {{ ucfirst($voice->mode ?? 'voice') }} clone
                                            @if ($voice->language) · {{ config('soundex.languages')[$voice->language] ?? $voice->language }} @endif
                                            · ID <span class="font-mono">{{ SettingsService::mask($voice->voice_id) }}</span>
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
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>

        <aside class="space-y-6">
            <div class="card p-6">
                <h2 class="text-base font-semibold text-slate-900">Default voice</h2>
                @if ($defaultVoice)
                    <p class="mt-2 text-2xl font-semibold text-slate-900">{{ $defaultVoice->name }}</p>
                    <p class="text-sm {{ $defaultVoice->isActive() ? 'text-emerald-600' : 'text-rose-600' }}">{{ $defaultVoice->isActive() ? 'Active and ready' : 'Not active in HeyGen — choose another voice' }}</p>
                @elseif ($defaultVoiceIdSet)
                    <p class="mt-2 text-sm text-amber-700">A default voice ID is configured but hasn't been loaded from HeyGen yet. Click “Refresh voice list”.</p>
                @else
                    <p class="mt-2 text-sm text-slate-500">Not selected. After refreshing, a voice named <strong>{{ $targetName }}</strong> is selected automatically the first time.</p>
                @endif
                <p class="hint mt-3">The selected voice is never replaced silently. If it disappears from HeyGen you'll be asked to choose.</p>
            </div>

            <div class="card p-6">
                <h2 class="text-base font-semibold text-slate-900">Add a voice by ID</h2>
                <p class="mt-1 text-sm text-slate-500">If your voice doesn't appear in the list, paste its HeyGen voice ID. It is verified with HeyGen before being added.</p>
                <form method="POST" action="{{ route('settings.voices.add') }}" class="mt-4 space-y-3">
                    @csrf
                    <label for="voice_id" class="sr-only">Voice ID</label>
                    <input id="voice_id" name="voice_id" class="input font-mono" placeholder="HeyGen voice ID" autocomplete="off" required @disabled(! $hasApiKey)>
                    <button class="btn-secondary w-full" @disabled(! $hasApiKey)>Verify &amp; add voice</button>
                </form>
                <p class="hint mt-3">Note: HeyGen Voice 1 requires clones made on the current voice model. Very old clones may need re-cloning inside HeyGen.</p>
            </div>
        </aside>
    </div>
</x-layouts.app>
