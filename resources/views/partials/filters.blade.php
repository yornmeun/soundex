<form method="GET" class="card mb-6 flex flex-col gap-3 p-4 sm:flex-row sm:items-center" role="search">
    <div class="relative flex-1">
        <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
        <label for="q" class="sr-only">Search</label>
        <input id="q" name="q" value="{{ $filters['q'] }}" placeholder="Search by project, file or voice…" class="input pl-9">
    </div>
    <label for="status" class="sr-only">Status</label>
    <select id="status" name="status" class="input sm:w-44" onchange="this.form.submit()">
        <option value="">All statuses</option>
        @foreach (\App\Enums\GenerationStatus::cases() as $status)
            <option value="{{ $status->value }}" @selected($filters['status'] === $status->value)>{{ $status->label() }}</option>
        @endforeach
    </select>
    <label for="sort" class="sr-only">Sort</label>
    <select id="sort" name="sort" class="input sm:w-36" onchange="this.form.submit()">
        <option value="newest" @selected($filters['sort'] === 'newest')>Newest first</option>
        <option value="oldest" @selected($filters['sort'] === 'oldest')>Oldest first</option>
    </select>
    <button class="btn-secondary">Search</button>
</form>
