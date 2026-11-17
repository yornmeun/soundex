{{-- Play / Download / View / Regenerate / Delete actions for a generation row. --}}
@props(['generation', 'play' => true])
@php($g = $generation)
<div class="flex items-center justify-end gap-0.5" x-data="{ playing: false }">
    @if ($g->hasAudio() && ! $play)
        <a href="{{ route('generations.download', $g) }}" class="icon-btn" title="Download" aria-label="Download {{ $g->project_name }}">
            <x-icon name="download" class="h-4 w-4" />
        </a>
    @elseif ($g->hasAudio())
        <audio x-ref="a" preload="none" src="{{ route('generations.stream', $g) }}" @play="playing = true" @pause="playing = false" @ended="playing = false"></audio>
        <button type="button" class="icon-btn" @click="$refs.a.paused ? $refs.a.play() : $refs.a.pause()" :aria-label="playing ? 'Pause' : 'Play'" title="Play">
            <x-icon name="play" class="h-4 w-4" x-show="!playing" />
            <x-icon name="pause" class="h-4 w-4 text-brand-600" x-show="playing" x-cloak />
        </button>
        <a href="{{ route('generations.download', $g) }}" class="icon-btn" title="Download" aria-label="Download {{ $g->project_name }}">
            <x-icon name="download" class="h-4 w-4" />
        </a>
    @else
        @if ($play)<span class="icon-btn cursor-not-allowed opacity-30" title="No audio yet"><x-icon name="play" class="h-4 w-4" /></span>@endif
        <span class="icon-btn cursor-not-allowed opacity-30" title="No audio yet"><x-icon name="download" class="h-4 w-4" /></span>
    @endif
    <a href="{{ route('generations.show', $g) }}" class="icon-btn" title="View details" aria-label="View details of {{ $g->project_name }}">
        <x-icon name="eye" class="h-4 w-4" />
    </a>
    <x-confirm-form :action="route('generations.regenerate', $g)" title="Regenerate narration?"
        message="A new generation will be created with the same script, voice and settings. This uses HeyGen credits."
        confirm-label="Regenerate">
        <button type="submit" class="icon-btn" title="Regenerate" aria-label="Regenerate {{ $g->project_name }}" @disabled($g->status->isActive())>
            <x-icon name="refresh" class="h-4 w-4" />
        </button>
    </x-confirm-form>
    <x-confirm-form :action="route('generations.destroy', $g)" method="DELETE" title="Delete generation?"
        message="This permanently deletes “{{ $g->project_name }}” and its audio files." confirm-label="Delete" :danger="true">
        <button type="submit" class="icon-btn hover:!bg-rose-50 hover:!text-rose-600" title="Delete" aria-label="Delete {{ $g->project_name }}" @disabled($g->status->isActive())>
            <x-icon name="trash" class="h-4 w-4" />
        </button>
    </x-confirm-form>
</div>
