{{--
    Item da barra lateral (gaveta de navegação Material 3).
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
    @if ($rota) wire:navigate @endif
    @if ($ativo) aria-current="page" @endif
    {{ $attributes->class([
        'relative flex h-14 items-center gap-3 overflow-hidden rounded-full px-4 text-sm font-medium tracking-[0.1px] transition',
        'before:absolute before:inset-0 before:bg-current before:opacity-0 before:transition-opacity hover:before:opacity-8',
        'bg-secondary-container text-on-secondary-container' => $ativo,
        'text-on-surface-variant' => ! $ativo,
    ]) }}>
    <x-ui.icone :nome="$icone" :cheio="$ativo" class="relative" />
    <span class="relative">{{ $slot }}</span>
</a>
