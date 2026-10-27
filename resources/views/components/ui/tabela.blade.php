{{-- Tabela de dados com rolagem horizontal. Cabeçalho no slot "cabecalho" (<x-ui.tabela.coluna>), linhas no slot padrão (<x-ui.tabela.linha wire:key="..."> com <x-ui.tabela.celula>). Com "vazio" e sem linhas, mostra o estado vazio (texto no slot "explicacao"). A tabela dos gráficos continua sendo <x-grafico.tabela>.
Ex.: <x-ui.tabela vazio="Nenhum ciclo ainda" largura="820px"> <x-slot:cabecalho> <x-ui.tabela.coluna>Ciclo</x-ui.tabela.coluna> <x-ui.tabela.coluna numero>Falhas</x-ui.tabela.coluna> </x-slot:cabecalho> ...linhas... </x-ui.tabela> --}}
@props([
    'cabecalho' => null,
    'explicacao' => null,
    'vazio' => null,
    'vazioIcone' => 'inbox',
    'largura' => null,
])

@if ($vazio !== null && ! $slot->hasActualContent())
    <x-ui.vazio :icone="$vazioIcone" :titulo="$vazio" class="py-8">{{ $explicacao }}</x-ui.vazio>
@else
    <div class="overflow-x-auto">
        <table {{ $attributes->class('w-full text-sm') }} @if ($largura) style="min-width: {{ $largura }}" @endif>
            @if ($cabecalho)
                <thead>
                    <tr class="text-xs text-on-surface-variant">{{ $cabecalho }}</tr>
                </thead>
            @endif
            <tbody>{{ $slot }}</tbody>
        </table>
    </div>
@endif
