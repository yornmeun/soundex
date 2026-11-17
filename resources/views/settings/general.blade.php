<x-layouts.app title="General Settings">
    <form method="POST" action="{{ route('settings.general.update') }}" class="card max-w-3xl space-y-8 p-6">
        @csrf
        @method('PUT')

        <section>
            <h2 class="text-base font-semibold text-slate-900">Generation defaults</h2>
            <p class="text-sm text-slate-500">Used to pre-fill new generations. You can change them per generation.</p>
            <div class="mt-5 grid gap-5 sm:grid-cols-2">
                <div>
                    <label for="model" class="label">HeyGen model</label>
                    <select id="model" name="model" class="input">
                        @foreach (config('soundex.models') as $key => $label)
                            <option value="{{ $key }}" @selected($values['generation.model'] === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="mp3_bitrate" class="label">MP3 quality</label>
                    <select id="mp3_bitrate" name="mp3_bitrate" class="input">
                        @foreach (config('soundex.mp3_bitrates') as $b)
                            <option value="{{ $b }}" @selected((int) $values['generation.mp3_bitrate'] === $b)>{{ $b / 1000 }} kbps</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="chunk_max_chars" class="label">Max characters per chunk</label>
                    <input id="chunk_max_chars" name="chunk_max_chars" type="number" min="500" max="{{ config('soundex.heygen.max_chars_per_request') }}" value="{{ $values['generation.chunk_max_chars'] }}" class="input">
                    <p class="hint">HeyGen's limit is {{ number_format(config('soundex.heygen.max_chars_per_request')) }} per request. Smaller chunks make retries cheaper.</p>
                </div>
                <div>
                    <label for="silence_between_ms" class="label">Pause between chunks (ms)</label>
                    <input id="silence_between_ms" name="silence_between_ms" type="number" min="0" max="5000" step="50" value="{{ $values['generation.silence_between_ms'] }}" class="input">
                </div>
            </div>
            <div class="mt-5 grid gap-3 sm:grid-cols-2">
                <label class="flex items-start gap-3 rounded-lg border border-slate-200 p-3">
                    <input type="hidden" name="normalize_loudness" value="0">
                    <input type="checkbox" name="normalize_loudness" value="1" @checked($values['generation.normalize_loudness']) class="mt-0.5 rounded border-slate-300 text-brand-600">
                    <span><span class="block text-sm font-medium">Normalize loudness</span><span class="text-xs text-slate-500">EBU R128 (−16 LUFS)</span></span>
                </label>
                <label class="flex items-start gap-3 rounded-lg border border-slate-200 p-3">
                    <input type="hidden" name="trim_chunk_silence" value="0">
                    <input type="checkbox" name="trim_chunk_silence" value="1" @checked($values['generation.trim_chunk_silence']) class="mt-0.5 rounded border-slate-300 text-brand-600">
                    <span><span class="block text-sm font-medium">Trim silence at chunk edges</span><span class="text-xs text-slate-500">Before inserting the pause</span></span>
                </label>
            </div>
        </section>

        <section>
            <h2 class="text-base font-semibold text-slate-900">Uploads</h2>
            <div class="mt-4 max-w-xs">
                <label for="max_upload_kb" class="label">Maximum SRT size (KB)</label>
                <input id="max_upload_kb" name="max_upload_kb" type="number" min="16" max="51200" value="{{ $values['upload.max_kb'] }}" class="input">
                <p class="hint">PHP's upload_max_filesize ({{ ini_get('upload_max_filesize') }}) also applies.</p>
            </div>
        </section>

        <div class="flex justify-end border-t border-slate-100 pt-5"><button class="btn-primary">Save settings</button></div>
    </form>
</x-layouts.app>
