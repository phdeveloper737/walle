{{-- Ícone Material Symbols. Ex.: <x-ui.icone nome="home" /> · <x-ui.icone nome="star" cheio class="text-[18px]" /> --}}
@props([
    'nome',
    'cheio' => false,
])

<span {{ $attributes->class(['material-symbols-outlined shrink-0 select-none', 'icone-cheio' => $cheio]) }} aria-hidden="true">{{ $nome }}</span>
