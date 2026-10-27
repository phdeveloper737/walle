@if ($this->clientes['erro'])
    <x-ui.aviso tipo="erro">
        O Help Desk (192.168.10.254) não respondeu. Os clientes aparecem pelo número até ele voltar.
    </x-ui.aviso>
@endif

<x-ui.card titulo="Últimas execuções" icone="history">
    <x-ui.tabela vazio="Nenhuma execução ainda" largura="720px">
        <x-slot:explicacao>O histórico começa quando o robô de tarefas rodar uma query ativa em algum cliente.</x-slot:explicacao>
        <x-slot:cabecalho>
            <x-ui.tabela.coluna>Quando</x-ui.tabela.coluna>
            <x-ui.tabela.coluna>Cliente</x-ui.tabela.coluna>
            <x-ui.tabela.coluna>Query</x-ui.tabela.coluna>
            <x-ui.tabela.coluna>Resultado</x-ui.tabela.coluna>
        </x-slot:cabecalho>
        @foreach ($this->execucoes as $i => $execucao)
            @php
                $resultado = \App\Livewire\Ferramentas::decodificarLog($execucao->log_query);
                $cliente = (int) $execucao->fk_c_cliente;
            @endphp
            <x-ui.tabela.linha wire:key="execucao-{{ $i }}">
                <x-ui.tabela.celula>{{ $execucao->data_time?->format('d/m/Y H:i') ?? '-' }}</x-ui.tabela.celula>
                <x-ui.tabela.celula>{{ $this->clientes['nomes'][$cliente] ?? '#'.$cliente }}</x-ui.tabela.celula>
                <x-ui.tabela.celula>{{ $execucao->taskSchedulerQuery?->descricao ?? '-' }}</x-ui.tabela.celula>
                <x-ui.tabela.celula>
                    @if ($resultado === 'OK')
                        <x-ui.etiqueta cor="sucesso">OK</x-ui.etiqueta>
                    @else
                        <span class="break-words">{{ $resultado }}</span>
                    @endif
                </x-ui.tabela.celula>
            </x-ui.tabela.linha>
        @endforeach
    </x-ui.tabela>
</x-ui.card>
