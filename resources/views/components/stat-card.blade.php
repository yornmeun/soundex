@props(['label', 'value', 'icon', 'tone' => 'brand'])
@php
    $tones = [
        'brand' => 'bg-brand-50 text-brand-600',
        'sky' => 'bg-sky-50 text-sky-600',
        'emerald' => 'bg-emerald-50 text-emerald-600',
        'rose' => 'bg-rose-50 text-rose-600',
    ];
@endphp
<div class="card flex items-center gap-4 p-5">
    <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl {{ $tones[$tone] }}">
        <x-icon :name="$icon" class="h-6 w-6" />
    </div>
    <div class="min-w-0">
        <p class="text-sm text-slate-500">{{ $label }}</p>
        <p class="truncate text-2xl font-semibold tracking-tight text-slate-900">{{ $value }}</p>
    </div>
</div>
