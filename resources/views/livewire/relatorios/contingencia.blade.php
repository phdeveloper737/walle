@php $historico = $this->historico; @endphp

<div class="max-w-xs">
    <x-ui.campo label="Código do cliente" tipo="number" icone="tag" wire:model.live.debounce.400ms="cliente" ajuda="Código do cliente no Help Desk." />
</div>

@if ($cliente === null)
    <x-ui.vazio icone="receipt_long" titulo="Escolha um cliente">
        Informe o código do cliente para ver o histórico de NFC-e em contingência.
    </x-ui.vazio>
@else
    <x-ui.card titulo="Histórico de contingências" icone="receipt_long">
        <p class="mb-2 text-sm text-on-surface-variant">
            {{ count($historico) }} {{ count($historico) === 1 ? 'registro' : 'registros' }}
            (os {{ \App\Livewire\Relatorios::LIMITE_HISTORICO }} mais recentes).
        </p>

        <x-ui.tabela vazio="Nenhuma contingência para este cliente" explicacao="O robô ainda não registrou contingência dele, ou o código não existe." largura="900px">
            <x-slot:cabecalho>
                <x-ui.tabela.coluna>Série</x-ui.tabela.coluna>
                <x-ui.tabela.coluna numero>Número</x-ui.tabela.coluna>
                <x-ui.tabela.coluna>Chave</x-ui.tabela.coluna>
                <x-ui.tabela.coluna>Motivo</x-ui.tabela.coluna>
                <x-ui.tabela.coluna>Software</x-ui.tabela.coluna>
                <x-ui.tabela.coluna>Data fiscal</x-ui.tabela.coluna>
            </x-slot:cabecalho>

            @foreach ($historico as $linha)
                <x-ui.tabela.linha wire:key="hist-{{ $loop->index }}-{{ $linha->chave }}">
                    <x-ui.tabela.celula>{{ $linha->serie }}</x-ui.tabela.celula>
                    <x-ui.tabela.celula numero>{{ $linha->numero }}</x-ui.tabela.celula>
                    <x-ui.tabela.celula class="font-mono text-xs">{{ $linha->chave }}</x-ui.tabela.celula>
                    <x-ui.tabela.celula destaque>{{ str_replace(['(', ')', '[', ']', 'nItem:'], '', trim((string) $linha->motivo)) }}</x-ui.tabela.celula>
                    <x-ui.tabela.celula>{{ $linha->software }}</x-ui.tabela.celula>
                    <x-ui.tabela.celula>{{ $linha->data_fiscal_xml ? substr($linha->data_fiscal_xml, 0, 10) : '' }}</x-ui.tabela.celula>
                </x-ui.tabela.linha>
            @endforeach
        </x-ui.tabela>
    </x-ui.card>
@endif
