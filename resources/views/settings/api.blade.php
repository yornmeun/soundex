<x-layouts.app title="API Settings">
    <div class="grid gap-6 lg:grid-cols-3">
        <form method="POST" action="{{ route('settings.api.update') }}" class="card space-y-6 p-6 lg:col-span-2" x-data="{ show: false }">
            @csrf
            @method('PUT')
            <div>
                <h2 class="text-base font-semibold text-slate-900">HeyGen connection</h2>
                <p class="text-sm text-slate-500">Your key is encrypted in the database and is never shown again or sent to the browser.</p>
            </div>

            <div>
                <label for="api_key" class="label">HeyGen API key</label>
                @if ($maskedKey)
                    <div class="mb-2 flex items-center gap-2 text-sm">
                        <span class="rounded-md bg-slate-100 px-2 py-1 font-mono text-slate-700">{{ $maskedKey }}</span>
                        <span class="text-xs text-slate-500">{{ $keySource === 'env' ? 'from .env (HEYGEN_API_KEY)' : 'stored encrypted' }}</span>
                    </div>
                @endif
                <div class="relative">
                    <input id="api_key" name="api_key" :type="show ? 'text' : 'password'" autocomplete="off" spellcheck="false" class="input pr-20 font-mono"
                           placeholder="{{ $maskedKey ? 'Leave blank to keep the current key' : 'Paste your HeyGen API key' }}">
                    <button type="button" class="absolute right-2 top-1/2 -translate-y-1/2 text-xs font-medium text-slate-500 hover:text-slate-800" @click="show = !show" x-text="show ? 'Hide' : 'Show'"></button>
                </div>
                <p class="hint">Find it in HeyGen → Settings → API. @error('api_key')<span class="text-rose-600">{{ $message }}</span>@enderror</p>
                @if ($keySource === 'database')
                    <label class="mt-2 flex items-center gap-2 text-sm text-slate-600"><input type="checkbox" name="remove_api_key" value="1" class="rounded border-slate-300 text-rose-600"> Remove stored key</label>
                @endif
            </div>

            <div class="border-t border-slate-100 pt-6" x-data="{ showAz: false }">
                <h2 class="text-base font-semibold text-slate-900">Microsoft Azure Speech <span class="font-normal text-slate-400">(optional)</span></h2>
                <p class="text-sm text-slate-500">Ready-made voices in 140+ languages, including Khmer. Free tier: about 500,000 characters per month.
                    Create a <em>Speech</em> resource in the Azure portal and copy <em>Key 1</em> and the <em>Location/Region</em>.</p>

                <div class="mt-4 grid gap-5 sm:grid-cols-3">
                    <div class="sm:col-span-2">
                        <label for="azure_key" class="label">Speech key</label>
                        @if ($maskedAzureKey)
                            <div class="mb-2 flex items-center gap-2 text-sm">
                                <span class="rounded-md bg-slate-100 px-2 py-1 font-mono text-slate-700">{{ $maskedAzureKey }}</span>
                                <span class="text-xs text-slate-500">{{ $azureKeySource === 'env' ? 'from .env (AZURE_SPEECH_KEY)' : 'stored encrypted' }}</span>
                            </div>
                        @endif
                        <div class="relative">
                            <input id="azure_key" name="azure_key" :type="showAz ? 'text' : 'password'" autocomplete="off" spellcheck="false" class="input pr-20 font-mono"
                                   placeholder="{{ $maskedAzureKey ? 'Leave blank to keep the current key' : 'Paste your Azure Speech key' }}">
                            <button type="button" class="absolute right-2 top-1/2 -translate-y-1/2 text-xs font-medium text-slate-500 hover:text-slate-800" @click="showAz = !showAz" x-text="showAz ? 'Hide' : 'Show'"></button>
                        </div>
                        @error('azure_key')<p class="hint text-rose-600">{{ $message }}</p>@enderror
                        @if ($azureKeySource === 'database')
                            <label class="mt-2 flex items-center gap-2 text-sm text-slate-600"><input type="checkbox" name="remove_azure_key" value="1" class="rounded border-slate-300 text-rose-600"> Remove stored key</label>
                        @endif
                    </div>
                    <div>
                        <label for="azure_region" class="label">Region</label>
                        <input id="azure_region" name="azure_region" value="{{ old('azure_region', $azureRegion) }}" autocomplete="off" class="input font-mono" placeholder="southeastasia">
                        @error('azure_region')<p class="hint text-rose-600">{{ $message }}</p>@enderror
                    </div>
                </div>
            </div>

            <div>
                <label for="default_voice_id" class="label">Default voice ID <span class="font-normal text-slate-400">(optional)</span></label>
                @if ($maskedVoiceId)<p class="mb-2 font-mono text-sm text-slate-600">Current: {{ $maskedVoiceId }}</p>@endif
                <input id="default_voice_id" name="default_voice_id" autocomplete="off" class="input font-mono" placeholder="Leave blank to keep / choose in Voice Settings">
                <p class="hint">Usually you don't need this: Voice Settings loads your voices and selects “{{ config('soundex.heygen.default_voice_name') }}”.</p>
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <label for="default_language" class="label">Default language</label>
                    <select id="default_language" name="default_language" class="input">
                        <option value="auto" @selected($defaultLanguage === 'auto')>Auto-detect</option>
                        @foreach (config('soundex.languages') as $code => $name)
                            <option value="{{ $code }}" @selected($defaultLanguage === $code)>{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="default_output_format" class="label">Default output format</label>
                    <select id="default_output_format" name="default_output_format" class="input">
                        @foreach (config('soundex.output_formats') as $key => $label)
                            <option value="{{ $key }}" @selected($defaultOutputFormat === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="flex justify-end border-t border-slate-100 pt-5"><button class="btn-primary">Save settings</button></div>
        </form>

        <aside class="space-y-6">
            <div class="card p-6">
                <h2 class="text-base font-semibold text-slate-900">Connection test</h2>
                <p class="mt-1 text-sm text-slate-500">Checks your key with HeyGen (<code>GET /v3/users/me</code>). No credits are used.</p>
                <form method="POST" action="{{ route('settings.api.test') }}" class="mt-4" x-data="{ busy: false }" @submit="busy = true">
                    @csrf
                    <button class="btn-secondary w-full" :disabled="busy" @disabled(! $maskedKey)><span x-text="busy ? 'Testing…' : 'Test connection'">Test connection</span></button>
                </form>
            </div>
            <div class="card p-6">
                <h2 class="text-base font-semibold text-slate-900">Microsoft Speech test</h2>
                <p class="mt-1 text-sm text-slate-500">Checks the key and region by loading the voice list. No characters are used.</p>
                <form method="POST" action="{{ route('settings.api.test-azure') }}" class="mt-4" x-data="{ busy: false }" @submit="busy = true">
                    @csrf
                    <button class="btn-secondary w-full" :disabled="busy" @disabled(! ($maskedAzureKey && $azureRegion))><span x-text="busy ? 'Testing…' : 'Test Microsoft Speech'">Test Microsoft Speech</span></button>
                </form>
            </div>
            <div class="card p-6">
                <h2 class="text-base font-semibold text-slate-900">System check</h2>
                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">HeyGen key</dt><dd class="{{ $maskedKey ? 'text-emerald-600' : 'text-slate-500' }}">{{ $maskedKey ? 'Configured' : 'Not set' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Microsoft Speech</dt><dd class="{{ $maskedAzureKey && $azureRegion ? 'text-emerald-600' : 'text-slate-500' }}">{{ $maskedAzureKey && $azureRegion ? 'Configured' : 'Not set' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">FFmpeg</dt><dd class="truncate {{ $ffmpegVersion ? 'text-emerald-600' : 'text-amber-600' }}" title="{{ $ffmpegVersion }}">{{ $ffmpegVersion ? 'Installed' : 'Not found (basic mode)' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Queue worker</dt><dd class="{{ $workerOnline ? 'text-emerald-600' : 'text-rose-600' }}">{{ $workerOnline ? 'Running' : 'Not running' }}</dd></div>
                </dl>
                @unless ($ffmpegVersion)
                    <p class="mt-3 rounded-lg bg-amber-50 p-3 text-xs text-amber-700">Running without FFmpeg: clips are joined in basic mode. Background music, sound effects and MP3 loudness normalisation need FFmpeg.</p>
                @endunless
            </div>
        </aside>
    </div>
</x-layouts.app>
