<div class="flex flex-col gap-3" role="status">
    <div class="flex items-center gap-3 text-sm text-on-surface-variant">
        <span class="size-5 animate-spin rounded-full border-2 border-primary border-t-transparent" aria-hidden="true"></span>
        Somando a base das autorizadas…
    </div>

    <div class="grid gap-3 xl:grid-cols-2">
        @foreach (range(1, 4) as $i)
            <div class="h-64 animate-pulse rounded-lg bg-surface-container-low"></div>
        @endforeach
    </div>
</div>
