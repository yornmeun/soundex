{{-- Live progress panel. Expects an enclosing x-data="generationStatus(...)" scope exposing `s`. --}}
<div class="space-y-5">
    <template x-if="!s">
        <div class="flex items-center gap-3 text-sm text-slate-500">
            <svg class="h-5 w-5 animate-spin text-brand-600" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-20"/><path d="M22 12a10 10 0 00-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
            Loading status…
        </div>
    </template>

    <template x-if="s">
        <div class="space-y-5">
            <div>
                <div class="mb-2 flex items-end justify-between gap-4">
                    <div>
                        <p class="text-sm font-semibold text-slate-900" x-text="s.status === 'pending' ? 'Waiting for the queue worker…' : (s.status === 'processing' ? s.stage_label + '…' : s.status_label)"></p>
                        <p class="text-xs text-slate-500" x-show="s.total_chunks > 0">
                            <span x-text="s.completed_chunks"></span> of <span x-text="s.total_chunks"></span> audio chunks generated
                        </p>
                    </div>
                    <span class="text-2xl font-semibold tabular-nums text-slate-900" x-text="s.progress + '%'"></span>
                </div>
                <div class="h-2.5 overflow-hidden rounded-full bg-slate-100" role="progressbar" :aria-valuenow="s.progress" aria-valuemin="0" aria-valuemax="100">
                    <div class="h-full rounded-full transition-all duration-700"
                         :class="{ 'bg-rose-500': s.status === 'failed', 'bg-amber-500': s.status === 'cancelled', 'bg-emerald-500': s.status === 'completed', 'bg-brand-600': ['pending','processing'].includes(s.status) }"
                         :style="`width: ${Math.max(2, s.progress)}%`"></div>
                </div>
            </div>

            <ol class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">
                <template x-for="st in s.stages" :key="st.value">
                    <li class="flex items-center gap-2 rounded-lg border px-3 py-2 text-xs font-medium"
                        :class="{
                            'border-emerald-200 bg-emerald-50 text-emerald-700': stageState(st.value) === 'done',
                            'border-brand-200 bg-brand-50 text-brand-700': stageState(st.value) === 'current',
                            'border-rose-200 bg-rose-50 text-rose-700': stageState(st.value) === 'error',
                            'border-slate-200 text-slate-400': stageState(st.value) === 'todo',
                        }">
                        <span class="flex h-4 w-4 items-center justify-center">
                            <svg x-show="stageState(st.value) === 'done'" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                            <svg x-show="stageState(st.value) === 'current'" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-20"/><path d="M22 12a10 10 0 00-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                            <span x-show="['todo','error'].includes(stageState(st.value))" class="h-1.5 w-1.5 rounded-full bg-current"></span>
                        </span>
                        <span x-text="st.label"></span>
                    </li>
                </template>
            </ol>

            <div x-show="s.cancel_requested && s.status === 'processing'" class="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800">
                Cancelling… the audio request currently in progress at HeyGen will finish first.
            </div>
            <div x-show="s.error_message && ['failed','cancelled'].includes(s.status)" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                <p class="font-medium" x-text="s.status === 'failed' ? 'Generation failed' : 'Generation cancelled'"></p>
                <p class="mt-1 break-words" x-text="s.error_message"></p>
            </div>
            <p x-show="error" class="text-sm text-amber-700" x-text="error"></p>
            <div x-show="s.status === 'pending' && !s.worker_online" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800" role="alert">
                <p class="font-semibold">The background worker is not running, so this generation can't start.</p>
                <p class="mt-1">Open a terminal in the project folder and run:</p>
                <code class="mt-2 block rounded bg-white px-3 py-2 font-mono text-xs text-slate-800 ring-1 ring-rose-200">php artisan queue:work --timeout=3600 --tries=1</code>
                <p class="mt-2 text-xs">Or use <code>composer start</code> to run the web server, worker and Vite together. The generation starts automatically once the worker is up; it stays queued until then.</p>
            </div>
            <p x-show="s.status === 'pending' && s.worker_online" class="text-xs text-slate-500">
                The worker is online. Waiting for it to pick up this job<span x-show="s.waiting_seconds > 30"> (another generation may be running first)</span>…
            </p>
        </div>
    </template>
</div>
