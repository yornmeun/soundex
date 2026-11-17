<x-layouts.guest>
    <div class="relative flex min-h-full items-center justify-center overflow-hidden px-4 py-12">
        <div class="pointer-events-none absolute -left-32 -top-32 h-96 w-96 rounded-full bg-brand-600/30 blur-3xl"></div>
        <div class="pointer-events-none absolute -bottom-32 -right-32 h-96 w-96 rounded-full bg-fuchsia-500/20 blur-3xl"></div>

        <div class="relative w-full max-w-sm">
            <div class="mb-8 flex flex-col items-center text-center">
                <div class="mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-to-br from-brand-500 to-fuchsia-500 text-white shadow-xl shadow-brand-900/50">
                    <x-icon name="wave" class="h-7 w-7" />
                </div>
                <h1 class="text-2xl font-bold tracking-tight text-white">Soundex</h1>
                <p class="mt-1 text-sm text-slate-400">Turn subtitles into narration with your own voice.</p>
            </div>

            <form method="POST" action="{{ route('login') }}" class="space-y-4 rounded-2xl bg-white p-6 shadow-2xl">
                @csrf
                <div>
                    <label for="email" class="label">Email</label>
                    <input id="email" name="email" type="email" autocomplete="email" required autofocus value="{{ old('email') }}" class="input">
                </div>
                <div>
                    <label for="password" class="label">Password</label>
                    <input id="password" name="password" type="password" autocomplete="current-password" required class="input">
                </div>
                @error('email')<p class="text-sm text-rose-600" role="alert">{{ $message }}</p>@enderror
                <label class="flex items-center gap-2 text-sm text-slate-600">
                    <input type="checkbox" name="remember" value="1" class="rounded border-slate-300 text-brand-600"> Remember me
                </label>
                <button class="btn-primary w-full">Sign in</button>
            </form>
            <p class="mt-6 text-center text-xs text-slate-500">Create an account with <code class="text-slate-400">php artisan soundex:user you@example.com</code></p>
        </div>
    </div>
</x-layouts.guest>
