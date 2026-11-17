@props(['icon' => 'wave', 'title', 'message' => null])
<div class="flex flex-col items-center justify-center px-6 py-14 text-center">
    <div class="mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-50 text-brand-600">
        <x-icon :name="$icon" class="h-7 w-7" />
    </div>
    <h3 class="text-base font-semibold text-slate-900">{{ $title }}</h3>
    @if ($message)
        <p class="mt-1 max-w-sm text-sm text-slate-500">{{ $message }}</p>
    @endif
    @if ($slot->isNotEmpty())
        <div class="mt-5">{{ $slot }}</div>
    @endif
</div>
