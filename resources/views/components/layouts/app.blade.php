@props(['title' => null])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-slate-50">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) ? $title.' · ' : '' }}Kikitoons Voice Generator</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='8' fill='%237c3aed'/><path d='M9 16h2m3-5v10m3-13v16m3-11v6m3-3h0' stroke='white' stroke-width='2.4' stroke-linecap='round'/></svg>">
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full font-sans text-slate-800 antialiased" x-data="{ sidebar: false }">
<a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded focus:bg-white focus:px-3 focus:py-2">Skip to content</a>

{{-- Mobile sidebar backdrop --}}
<div x-show="sidebar" x-transition.opacity x-cloak class="fixed inset-0 z-30 bg-slate-900/60 lg:hidden" @click="sidebar = false"></div>

{{-- Sidebar --}}
<aside class="fixed inset-y-0 left-0 z-40 flex w-64 -translate-x-full flex-col bg-slate-900 transition-transform duration-200 lg:translate-x-0"
       :class="sidebar && '!translate-x-0'" aria-label="Main navigation">
    <div class="flex h-16 items-center gap-3 px-5">
        <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-brand-500 to-fuchsia-500 text-white shadow-lg shadow-brand-900/40">
            <x-icon name="wave" class="h-5 w-5" />
        </div>
        <div class="leading-tight">
            <p class="text-sm font-bold tracking-tight text-white">Kikitoons</p>
            <p class="text-xs text-slate-400">Voice Generator</p>
        </div>
        <button class="ml-auto text-slate-400 hover:text-white lg:hidden" @click="sidebar = false" aria-label="Close menu"><x-icon name="x" /></button>
    </div>

    <nav class="flex-1 space-y-1 overflow-y-auto px-3 py-4">
        <x-nav-link :href="route('dashboard')" icon="home" :active="request()->routeIs('dashboard')">Dashboard</x-nav-link>
        <x-nav-link :href="route('generations.create')" icon="plus" :active="request()->routeIs('generations.create')">New Generation</x-nav-link>
        <x-nav-link :href="route('library')" icon="library" :active="request()->routeIs('library')">My Audio Library</x-nav-link>
        <x-nav-link :href="route('history')" icon="clock" :active="request()->routeIs('history', 'generations.show')">Generation History</x-nav-link>

        <p class="px-3 pb-1 pt-6 text-[11px] font-semibold uppercase tracking-wider text-slate-500">Settings</p>
        <x-nav-link :href="route('settings.voices')" icon="mic" :active="request()->routeIs('settings.voices*')">Voice Settings</x-nav-link>
        <x-nav-link :href="route('settings.api')" icon="key" :active="request()->routeIs('settings.api*')">API Settings</x-nav-link>
        <x-nav-link :href="route('settings.general')" icon="cog" :active="request()->routeIs('settings.general*')">General Settings</x-nav-link>
    </nav>

    <div class="border-t border-white/10 p-4">
        <div class="flex items-center gap-3">
            <div class="flex h-9 w-9 items-center justify-center rounded-full bg-slate-700 text-sm font-semibold text-white">
                {{ strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}
            </div>
            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-medium text-white">{{ auth()->user()->name }}</p>
                <p class="truncate text-xs text-slate-400">{{ auth()->user()->email }}</p>
            </div>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="rounded-lg p-1.5 text-slate-400 hover:bg-white/10 hover:text-white" title="Sign out" aria-label="Sign out"><x-icon name="logout" /></button>
            </form>
        </div>
    </div>
</aside>

<div class="lg:pl-64">
    {{-- Top bar --}}
    <header class="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-slate-200 bg-white/80 px-4 backdrop-blur sm:px-6 lg:px-8">
        <button class="icon-btn lg:hidden" @click="sidebar = true" aria-label="Open menu"><x-icon name="menu" /></button>
        <h1 class="min-w-0 flex-1 truncate text-lg font-semibold text-slate-900">{{ $title ?? 'Dashboard' }}</h1>

        @php($unread = auth()->user()->unreadNotifications()->latest()->limit(6)->get())
        <div class="relative" x-data="{ open: false }" @click.outside="open = false" @keydown.escape="open = false">
            <button class="icon-btn relative" @click="open = !open" aria-label="Notifications" :aria-expanded="open">
                <x-icon name="bell" />
                @if ($unread->isNotEmpty())
                    <span class="absolute right-1 top-1 h-2 w-2 rounded-full bg-rose-500"></span>
                @endif
            </button>
            <div x-show="open" x-transition x-cloak class="absolute right-0 mt-2 w-80 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xl">
                <div class="flex items-center justify-between border-b border-slate-100 px-4 py-2.5">
                    <p class="text-sm font-semibold">Notifications</p>
                    @if ($unread->isNotEmpty())
                        <form method="POST" action="{{ route('notifications.read') }}">@csrf<button class="text-xs font-medium text-brand-600 hover:underline">Mark all read</button></form>
                    @endif
                </div>
                @forelse ($unread as $n)
                    <a href="{{ route('generations.show', $n->data['generation_id'] ?? 0) }}" class="flex gap-3 px-4 py-3 text-sm hover:bg-slate-50">
                        <span @class(['mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full', 'bg-emerald-50 text-emerald-600' => $n->data['succeeded'] ?? false, 'bg-rose-50 text-rose-600' => ! ($n->data['succeeded'] ?? false)])>
                            <x-icon :name="($n->data['succeeded'] ?? false) ? 'check' : 'warning'" class="h-4 w-4" />
                        </span>
                        <span><span class="block text-slate-700">{{ $n->data['message'] ?? '' }}</span><span class="text-xs text-slate-400">{{ $n->created_at->diffForHumans() }}</span></span>
                    </a>
                @empty
                    <p class="px-4 py-6 text-center text-sm text-slate-500">You're all caught up.</p>
                @endforelse
            </div>
        </div>

        <a href="{{ route('generations.create') }}" class="btn-primary hidden sm:inline-flex"><x-icon name="plus" class="h-4 w-4" /> New Generation</a>
    </header>

    <main id="main" class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        {{ $slot }}
    </main>
</div>

{{-- Flash messages → toasts --}}
<div x-data x-init="
        @foreach (['success', 'error', 'warning'] as $type)
            @if (session($type)) $store.toasts.push(@js(session($type)), '{{ $type }}', {{ $type === 'success' ? 6000 : 12000 }}); @endif
        @endforeach
        @if ($errors->any()) $store.toasts.push(@js($errors->first()), 'error', 10000); @endif
     "
     class="pointer-events-none fixed inset-x-0 bottom-0 z-50 flex flex-col items-center gap-2 p-4 sm:items-end sm:p-6" aria-live="polite">
    <template x-for="t in $store.toasts.items" :key="t.id">
        <div x-transition class="pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-xl border bg-white p-4 shadow-lg"
             :class="{ 'border-emerald-200': t.type === 'success', 'border-rose-200': t.type === 'error', 'border-amber-200': t.type === 'warning' }">
            <span class="mt-0.5 h-2.5 w-2.5 shrink-0 rounded-full" :class="{ 'bg-emerald-500': t.type === 'success', 'bg-rose-500': t.type === 'error', 'bg-amber-500': t.type === 'warning' }"></span>
            <p class="flex-1 text-sm text-slate-700" x-text="t.message"></p>
            <button class="text-slate-400 hover:text-slate-600" @click="$store.toasts.remove(t.id)" aria-label="Dismiss"><x-icon name="x" class="h-4 w-4" /></button>
        </div>
    </template>
</div>

{{-- Confirmation modal --}}
<div x-data x-show="$store.confirm.open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" role="dialog" aria-modal="true" @keydown.escape.window="$store.confirm.cancel()">
    <div class="absolute inset-0 bg-slate-900/50" @click="$store.confirm.cancel()"></div>
    <div x-show="$store.confirm.open" x-transition class="relative w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl">
        <div class="flex gap-4">
            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full" :class="$store.confirm.danger ? 'bg-rose-50 text-rose-600' : 'bg-brand-50 text-brand-600'">
                <x-icon name="warning" />
            </div>
            <div>
                <h2 class="text-base font-semibold text-slate-900" x-text="$store.confirm.title"></h2>
                <p class="mt-1 text-sm text-slate-600" x-text="$store.confirm.message"></p>
            </div>
        </div>
        <div class="mt-6 flex justify-end gap-2">
            <button class="btn-secondary" @click="$store.confirm.cancel()">Cancel</button>
            <button :class="$store.confirm.danger ? 'btn-danger' : 'btn-primary'" @click="$store.confirm.accept()" x-text="$store.confirm.confirmLabel" x-init="$watch('$store.confirm.open', v => v && $nextTick(() => $el.focus()))"></button>
        </div>
    </div>
</div>
</body>
</html>
