{{-- Chip de filtro (Material 3). Ex.: <x-ui.chip :ativo="$filtro === 'falhas'" :contagem="8" wire:click="$set('filtro', 'falhas')">Com falha</x-ui.chip> --}}
@props([
    'ativo' => false,
    'contagem' => null,
])

<button type="button" aria-pressed="{{ $ativo ? 'true' : 'false' }}" {{ $attributes->class([
    'relative inline-flex h-8 shrink-0 items-center gap-1.5 overflow-hidden rounded-sm border px-3 text-xs font-medium transition',
    'before:absolute before:inset-0 before:bg-current before:opacity-0 before:transition-opacity hover:before:opacity-8',
    'border-transparent bg-secondary-container text-on-secondary-container' => $ativo,
    'border-outline text-on-surface-variant' => ! $ativo,
]) }}>
    @if ($ativo)
        <x-ui.icone nome="check" class="relative text-[16px]" />
    @endif
    <span class="relative">{{ $slot }}</span>
    @if ($contagem !== null)
        <span class="relative tabular-nums opacity-80">{{ \App\Support\Numero::inteiro($contagem) }}</span>
    @endif
</button>
