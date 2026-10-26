<div class="flex flex-col gap-4" role="status" aria-live="polite" aria-atomic="true">
    <x-ui.vazio icone="receipt_long" titulo="Buscando contingências">
        <span class="flex flex-col items-center gap-3">
            <span class="size-7 animate-spin rounded-full border-2 border-outline-variant border-t-primary motion-reduce:animate-none" aria-hidden="true"></span>
            <span>Estamos consultando o servidor do cliente. Isso pode levar alguns segundos.</span>
        </span>
    </x-ui.vazio>

    @foreach (range(1, 3) as $i)
        <div class="h-32 animate-pulse rounded-md bg-surface-container-high motion-reduce:animate-none" aria-hidden="true"></div>
    @endforeach
</div>
