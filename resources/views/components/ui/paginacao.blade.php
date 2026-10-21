{{--
    Paginação simples ("1–20 de 516" + anterior/próxima), chamando um método do componente Livewire.
    Ex.: <x-ui.paginacao :pagina="$pagina" :total-paginas="$totalPaginas" :total="$total" :por-pagina="20" acao="irParaPagina" />
--}}
@props([
    'pagina',
    'totalPaginas',
    'total',
    'porPagina',
    'acao' => 'irParaPagina',
])

@if ($totalPaginas > 1)
    <nav {{ $attributes->class('flex items-center justify-end gap-2 text-sm text-on-surface-variant') }} aria-label="Paginação">
        <span>{{ ($pagina - 1) * $porPagina + 1 }}–{{ min($pagina * $porPagina, $total) }} de {{ $total }}</span>
        <x-ui.botao-icone icone="chevron_left" rotulo="Página anterior" wire:click="{{ $acao }}({{ $pagina - 1 }})" :disabled="$pagina <= 1" />
        <x-ui.botao-icone icone="chevron_right" rotulo="Próxima página" wire:click="{{ $acao }}({{ $pagina + 1 }})" :disabled="$pagina >= $totalPaginas" />
    </nav>
@endif
