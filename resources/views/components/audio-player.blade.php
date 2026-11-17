{{-- Waveform audio player. Use :lazy="true" in lists so audio is only fetched on play. --}}
@props(['src', 'downloadUrl' => null, 'duration' => null, 'lazy' => false, 'compact' => false])
<div x-data="waveformPlayer(@js($src), @js(['lazy' => (bool) $lazy, 'duration' => $duration]))"
     {{ $attributes->merge(['class' => 'rounded-xl border border-slate-200 bg-slate-50 '.($compact ? 'p-2' : 'p-3')]) }}>
    <audio x-ref="audio" src="{{ $src }}" preload="{{ $lazy ? 'none' : 'metadata' }}"></audio>
    <div class="flex items-center {{ $compact ? 'gap-2' : 'gap-3' }}">
        <button type="button" @click="toggle()"
                class="flex {{ $compact ? 'h-9 w-9' : 'h-11 w-11' }} shrink-0 items-center justify-center rounded-full bg-brand-600 text-white shadow hover:bg-brand-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2"
                :aria-label="playing ? 'Pause' : 'Play'">
            <x-icon name="play" class="ml-0.5 {{ $compact ? 'h-4 w-4' : 'h-5 w-5' }}" x-show="!playing" />
            <x-icon name="pause" class="{{ $compact ? 'h-4 w-4' : 'h-5 w-5' }}" x-show="playing" x-cloak />
        </button>
        <div class="min-w-0 flex-1">
            <canvas x-ref="canvas" class="{{ $compact ? 'h-9' : 'h-12' }} w-full cursor-pointer" @click="seek($event)" tabindex="0" @keydown="seekKey($event)"
                    role="slider" aria-label="Seek audio" :aria-valuenow="Math.round(current)" :aria-valuemax="Math.round(duration)"></canvas>
        </div>
        <span class="{{ $compact ? 'w-[4.5rem] text-[11px]' : 'w-24 text-xs' }} shrink-0 text-right font-mono tabular-nums text-slate-500" x-text="timeLabel"></span>
        @if ($downloadUrl)
            <a href="{{ $downloadUrl }}" class="icon-btn shrink-0" title="Download" aria-label="Download audio"><x-icon name="download" class="h-5 w-5" /></a>
        @endif
    </div>
</div>
