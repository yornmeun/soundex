<x-layouts.app title="Pronunciation Dictionary">
    <div class="grid gap-6 lg:grid-cols-3">
        <div class="card overflow-hidden lg:col-span-2" x-data="{
                playing: null,
                async preview(term, ipa, id) {
                    this.playing = id;
                    try {
                        const res = await api(@js(route('pronunciations.preview')), { method: 'POST', body: { term, ipa } });
                        const audio = new Audio(res.audio_url);
                        audio.onended = () => this.playing = null;
                        await audio.play();
                    } catch (e) { $store.toasts.push(e.message, 'error'); this.playing = null; }
                }
            }">
            <div class="border-b border-slate-100 px-5 py-4">
                <h2 class="text-base font-semibold text-slate-900">Your terms <span class="font-normal text-slate-500">({{ $entries->count() }})</span></h2>
                <p class="text-sm text-slate-500">Matched case-insensitively as whole words. Only the terms that appear in a chunk are sent to HeyGen (max 100 per request).</p>
            </div>
            @if ($entries->isEmpty())
                <x-empty-state icon="speech" title="No pronunciations yet" message="Add names and unusual words — for example “Kiki” → /ˈkiːki/ — so the narration says them the way you want." />
            @else
                <ul class="divide-y divide-slate-100">
                    @foreach ($entries as $entry)
                        <li class="px-5 py-3" x-data="{ editing: false }">
                            <div class="flex flex-wrap items-center gap-3" x-show="!editing">
                                <div class="min-w-0 flex-1">
                                    <p class="font-medium {{ $entry->is_active ? 'text-slate-900' : 'text-slate-400 line-through' }}">{{ $entry->term }}</p>
                                    <p class="font-mono text-sm text-brand-700">{{ $entry->ipa }}</p>
                                    @if ($entry->note)<p class="text-xs text-slate-500">{{ $entry->note }}</p>@endif
                                </div>
                                <button type="button" class="btn-secondary btn-sm" @click="preview(@js($entry->term), @js($entry->ipa), {{ $entry->id }})" :disabled="playing !== null" title="Speaks the word with your default voice (uses a few HeyGen credits)">
                                    <x-icon name="play" class="h-4 w-4" /> <span x-text="playing === {{ $entry->id }} ? 'Playing…' : 'Hear it'"></span>
                                </button>
                                <button type="button" class="btn-ghost btn-sm" @click="editing = true">Edit</button>
                                <x-confirm-form :action="route('pronunciations.destroy', $entry)" method="DELETE" title="Remove pronunciation?" message="“{{ $entry->term }}” will be removed from your dictionary." confirm-label="Remove" :danger="true">
                                    <button class="icon-btn hover:!text-rose-600" aria-label="Remove {{ $entry->term }}"><x-icon name="trash" class="h-4 w-4" /></button>
                                </x-confirm-form>
                            </div>
                            <form x-show="editing" x-cloak method="POST" action="{{ route('pronunciations.update', $entry) }}" class="grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
                                @csrf @method('PUT')
                                <div><label class="label">Term</label><input name="term" value="{{ $entry->term }}" maxlength="128" class="input" required></div>
                                <div><label class="label">IPA</label><input name="ipa" value="{{ $entry->ipa }}" maxlength="512" class="input font-mono" required></div>
                                <div class="flex items-center gap-3">
                                    <label class="flex items-center gap-1.5 text-sm text-slate-600"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked($entry->is_active) class="rounded border-slate-300 text-brand-600"> Active</label>
                                    <button class="btn-primary btn-sm">Save</button>
                                    <button type="button" class="btn-ghost btn-sm" @click="editing = false">Cancel</button>
                                </div>
                                <input type="hidden" name="note" value="{{ $entry->note }}">
                            </form>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        <aside class="space-y-6">
            <form method="POST" action="{{ route('pronunciations.store') }}" class="card space-y-4 p-6" x-data="{ term: @js(old('term', '')), ipa: @js(old('ipa', '')), busy: false }">
                @csrf
                <h2 class="text-base font-semibold text-slate-900">Add a term</h2>
                <div>
                    <label for="p-term" class="label">Word or name</label>
                    <input id="p-term" name="term" x-model="term" maxlength="128" required class="input" placeholder="Kiki">
                    @error('term')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="p-ipa" class="label">Pronunciation (IPA)</label>
                    <input id="p-ipa" name="ipa" x-model="ipa" maxlength="512" required class="input font-mono" placeholder="/ˈkiːki/">
                    @error('ipa')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                    <p class="hint">International Phonetic Alphabet between slashes. Slashes are added for you if missing.</p>
                </div>
                <div>
                    <label for="p-note" class="label">Note <span class="font-normal text-slate-400">(optional)</span></label>
                    <input id="p-note" name="note" maxlength="255" class="input" placeholder="Main character">
                </div>
                <div class="flex gap-2">
                    <button class="btn-primary flex-1"><x-icon name="plus" class="h-4 w-4" /> Add</button>
                    <button type="button" class="btn-secondary" :disabled="!term || !ipa || busy" title="Uses a few HeyGen credits"
                            @click="busy = true; let v = ipa.trim(); if (!v.startsWith('/')) v = '/' + v; if (!v.endsWith('/')) v += '/';
                                    api(@js(route('pronunciations.preview')), { method: 'POST', body: { term, ipa: v } })
                                        .then(r => new Audio(r.audio_url).play()).catch(e => $store.toasts.push(e.message, 'error')).finally(() => busy = false)">
                        <x-icon name="play" class="h-4 w-4" /> <span x-text="busy ? '…' : 'Hear it'"></span>
                    </button>
                </div>
            </form>

            <div class="card space-y-2 p-6 text-sm text-slate-600">
                <h2 class="text-base font-semibold text-slate-900">How it works</h2>
                <p>Terms are sent with HeyGen's <code>pronunciation_dictionary</code>. HeyGen applies them to <strong>instant voice clones</strong> on <em>Voice 1</em> and <em>Voice 1 Turbo</em> only — not to professional clones or Turbo Free.</p>
                <p>“Hear it” speaks the word with your default voice{{ $defaultVoice ? ' ('.$defaultVoice->name.')' : '' }}. It is a real HeyGen request and uses a few credits.</p>
                <p>Changed a pronunciation? Use <strong>Regenerate</strong> on a narration — it uses your current dictionary.</p>
            </div>
        </aside>
    </div>
</x-layouts.app>
