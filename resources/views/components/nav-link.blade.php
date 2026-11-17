@props(['href', 'icon', 'active' => false])
<a href="{{ $href }}" @class([
        'group flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition',
        'bg-white/10 text-white' => $active,
        'text-slate-400 hover:bg-white/5 hover:text-white' => ! $active,
    ]) @if($active) aria-current="page" @endif>
    <x-icon :name="$icon" @class(['h-5 w-5', 'text-brand-400' => $active, 'text-slate-500 group-hover:text-slate-300' => ! $active]) />
    {{ $slot }}
</a>
