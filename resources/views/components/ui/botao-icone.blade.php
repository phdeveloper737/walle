{{-- Botão só com ícone (Material 3). rotulo vira a dica e o texto para leitor de tela. Ex.: <x-ui.botao-icone icone="refresh" rotulo="Atualizar" wire:click="recarregar" /> --}}
@props([
    'icone',
    'rotulo',
    'type' => 'button',
])

<button type="{{ $type }}" title="{{ $rotulo }}" aria-label="{{ $rotulo }}"
    {{ $attributes->class('relative flex size-10 shrink-0 items-center justify-center overflow-hidden rounded-full text-on-surface-variant transition before:absolute before:inset-0 before:bg-current before:opacity-0 before:transition-opacity hover:before:opacity-8 focus-visible:before:opacity-10 disabled:pointer-events-none disabled:opacity-40') }}>
    <x-ui.icone :nome="$icone" class="relative" />
</button>
