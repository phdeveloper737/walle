{{-- Estado vazio ("nada aqui ainda"). Ex.: <x-ui.vazio icone="search_off" titulo="Nenhum cliente encontrado">Tente outro termo.</x-ui.vazio> --}}
@props([
    'icone' => 'inbox',
    'titulo',
])

<div {{ $attributes->class('flex flex-col items-center justify-center gap-3 px-6 py-16 text-center') }}>
    <span class="flex size-16 items-center justify-center rounded-full bg-surface-container-high text-secondary">
        <x-ui.icone :nome="$icone" class="text-[32px]" />
    </span>
    <h3 class="text-lg text-on-surface">{{ $titulo }}</h3>
    @if ($slot->isNotEmpty())
        <p class="max-w-sm text-sm text-on-surface-variant">{{ $slot }}</p>
    @endif
</div>
