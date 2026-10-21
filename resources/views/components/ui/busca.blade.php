{{--
    Barra de busca Material 3 (arredondada, com botão de limpar).
    Ex.: <x-ui.busca placeholder="Buscar cliente" wire:model.live.debounce.250ms="busca" />
--}}
@props([
    'placeholder' => 'Buscar',
])

@php
    $modelo = $attributes->wire('model')->value();
@endphp

<div {{ $attributes->only('class')->class('relative') }}>
    <x-ui.icone nome="search" class="pointer-events-none absolute top-1/2 left-4 -translate-y-1/2 text-on-surface-variant" />

    <input
        type="search"
        placeholder="{{ $placeholder }}"
        aria-label="{{ $placeholder }}"
        autocomplete="off"
        {{ $attributes->except('class')->class('h-12 w-full rounded-full bg-surface-container-high pr-12 pl-12 text-base text-on-surface outline-none transition placeholder:text-on-surface-variant focus:bg-surface-container-highest [&::-webkit-search-cancel-button]:hidden') }}
    />

    @if ($modelo)
        <button type="button" x-cloak x-show="$wire.{{ $modelo }}" x-on:click="$wire.set('{{ $modelo }}', '')"
            class="absolute top-1/2 right-1 flex size-10 -translate-y-1/2 items-center justify-center rounded-full text-on-surface-variant transition hover:bg-on-surface/8"
            aria-label="Limpar busca">
            <x-ui.icone nome="close" class="text-[20px]" />
        </button>
    @endif
</div>
