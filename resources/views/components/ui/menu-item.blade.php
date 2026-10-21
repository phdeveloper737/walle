{{--
    Item da barra lateral (gaveta de navegação Material 3; com o menu recolhido vira só o ícone).
    rota: nome da rota do Laravel (fica ativo nela). href: link externo, sem navegação do Livewire.
    Ex.: <x-ui.menu-item rota="painel" icone="space_dashboard">Painel</x-ui.menu-item>
--}}
@props([
    'icone',
    'rota' => null,
    'href' => null,
])

@php
    $ativo = $rota !== null && request()->routeIs($rota);
    $destino = $href ?? route($rota);
@endphp

<a href="{{ $destino }}"
    title="{{ trim(strip_tags($slot)) }}"
    @if ($rota) wire:navigate @endif
    @if ($ativo) aria-current="page" @endif
    x-bind:class="recolhido && 'lg:justify-center lg:px-0'"
    {{ $attributes->class([
        'relative flex h-12 items-center gap-3 overflow-hidden rounded-full px-4 text-sm font-medium tracking-[0.1px] transition',
        'before:absolute before:inset-0 before:bg-current before:opacity-0 before:transition-opacity hover:before:opacity-8',
        'bg-secondary-container text-on-secondary-container' => $ativo,
        'text-on-surface-variant' => ! $ativo,
    ]) }}>
    <x-ui.icone :nome="$icone" :cheio="$ativo" class="relative" />
    <span class="relative truncate" x-bind:class="recolhido && 'lg:sr-only'">{{ $slot }}</span>
</a>
