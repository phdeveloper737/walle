{{--
    Botão Material 3.
    variante: preenchido (padrão) | tonal | contorno | texto
    Com href vira link. Ex.: <x-ui.botao icone="add" wire:click="novo">Novo</x-ui.botao>
--}}
@props([
    'variante' => 'preenchido',
    'icone' => null,
    'href' => null,
    'type' => 'button',
])

@php
    $cores = match ($variante) {
        'tonal' => 'bg-secondary-container text-on-secondary-container',
        'contorno' => 'border border-outline text-primary',
        'texto' => 'px-3 text-primary',
        default => 'bg-primary-container text-on-primary-container',
    };

    // "before:" é a camada de estado do Material (8% ao passar o mouse, 10% ao focar/apertar), na cor do texto
    $classes = 'relative inline-flex h-10 shrink-0 items-center justify-center gap-2 overflow-hidden rounded-full px-6 '
        .'text-sm font-medium tracking-[0.1px] transition '
        .'before:absolute before:inset-0 before:bg-current before:opacity-0 before:transition-opacity '
        .'hover:before:opacity-8 focus-visible:before:opacity-10 active:before:opacity-10 '
        .'disabled:pointer-events-none disabled:opacity-40 '.$cores;
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>
        @if ($icone)
            <x-ui.icone :nome="$icone" class="relative text-[18px]" />
        @endif
        <span class="relative">{{ $slot }}</span>
    </a>
@else
    <button type="{{ $type }}" {{ $attributes->class($classes) }}>
        @if ($icone)
            <x-ui.icone :nome="$icone" class="relative text-[18px]" />
        @endif
        <span class="relative">{{ $slot }}</span>
    </button>
@endif
