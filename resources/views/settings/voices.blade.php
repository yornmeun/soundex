@use('App\Services\SettingsService')
<x-layouts.app title="Voice Settings">
    @php
        $heygenVoices = $voices->reject(fn ($v) => $v->isAzure());
        $microsoftVoices = $voices->filter(fn ($v) => $v->isAzure());
    @endphp
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
                @elseif ($heygenVoices->isEmpty())
                    <x-empty-state icon="mic" title="No voices loaded" message="Click “Refresh voice list” to load the voices from your HeyGen workspace, including {{ $targetName }}." />
                @else
                    <ul class="divide-y divide-slate-100">
                        @foreach ($heygenVoices as $voice)
                            @include('settings.partials.voice-row', ['voice' => $voice])
                        @endforeach
                    </ul>
                @endif
            </div>
            <div class="card overflow-hidden">
                <div class="flex flex-col gap-3 border-b border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 class="text-base font-semibold text-slate-900">Microsoft voices</h2>
                        <p class="text-sm text-slate-500">Ready-made neural voices from Azure Speech, including Khmer (Sreymom, Piseth). Languages: {{ implode(', ', $azureLocales) }}.</p>
                    </div>
                    <form method="POST" action="{{ route('settings.voices.refresh-azure') }}" x-data="{ busy: false }" @submit="busy = true">
                        @csrf
                        <button class="btn-primary btn-sm" :disabled="busy" @disabled(! $hasAzure)>
                            <x-icon name="refresh" class="h-4 w-4" x-bind:class="busy && 'animate-spin'" /> <span x-text="busy ? 'Loading…' : 'Load Microsoft voices'">Load Microsoft voices</span>
                        </button>
                    </form>
                </div>

                @if (! $hasAzure)
                    <x-empty-state icon="key" title="Connect Microsoft Speech" message="Add your Azure Speech key and region to use Microsoft voices, including Khmer.">
                        <a href="{{ route('settings.api') }}" class="btn-primary">API Settings</a>
                    </x-empty-state>
                @elseif ($microsoftVoices->isEmpty())
                    <x-empty-state icon="mic" title="No Microsoft voices loaded" message="Click “Load Microsoft voices”." />
                @else
                    <ul class="divide-y divide-slate-100">
                        @foreach ($microsoftVoices as $voice)
                            @include('settings.partials.voice-row', ['voice' => $voice])
                        @endforeach
                    </ul>
                    <p class="border-t border-slate-100 px-5 py-3 text-xs text-slate-500">The first preview of each voice is a short real request (a few characters of your Azure allowance); after that it's cached.</p>
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
