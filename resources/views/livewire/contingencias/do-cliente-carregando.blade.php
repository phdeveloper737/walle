<div class="flex flex-col gap-4" role="status">
    <div class="flex items-center gap-3 text-sm text-on-surface-variant">
        <span class="size-5 animate-spin rounded-full border-2 border-primary border-t-transparent" aria-hidden="true"></span>
        Consultando as contingências no servidor do cliente… pode levar alguns segundos.
    </div>

    @foreach (range(1, 3) as $i)
        <div class="h-32 animate-pulse rounded-md bg-surface-container-high"></div>
    @endforeach
</div>
