@props(['status'])
<span {{ $attributes->merge(['class' => 'badge '.$status->badgeClasses()]) }}>
    @if ($status->value === 'processing')
        <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-sky-500"></span>
    @endif
    {{ $status->label() }}
</span>
