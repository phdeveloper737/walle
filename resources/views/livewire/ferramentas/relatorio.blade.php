<div class="flex flex-col gap-3">
    @if ($this->nomes === null)
        <x-ui.aviso tipo="erro">O Help Desk (192.168.10.254) não respondeu. Os nomes dos clientes não aparecem; o resto do relatório é do walle.</x-ui.aviso>
    @endif

    <x-ui.card titulo="Últimas execuções" icone="summarize">
        <x-ui.tabela vazio="Nenhuma execução registrada" vazioIcone="history" largura="720px">
            <x-slot:explicacao>O relatório começa quando o robô rodar a primeira query agendada num cliente.</x-slot:explicacao>
            <x-slot:cabecalho>
                <x-ui.tabela.coluna>Quando</x-ui.tabela.coluna>
                <x-ui.tabela.coluna>Query</x-ui.tabela.coluna>
                <x-ui.tabela.coluna>Cliente</x-ui.tabela.coluna>
                <x-ui.tabela.coluna>Resultado</x-ui.tabela.coluna>
            </x-slot:cabecalho>
            @foreach ($this->logs as $indice => $log)
                <x-ui.tabela.linha wire:key="log-{{ $indice }}">
                    <x-ui.tabela.celula>{{ $log->data_time?->format('d/m/Y H:i:s') ?? '—' }}</x-ui.tabela.celula>
                    <x-ui.tabela.celula>
                        {{ $log->taskSchedulerQuery?->descricao ?? '—' }}
                        <span class="text-xs text-on-surface-variant">#{{ $log->fk_task_scheduler_querys }}</span>
                    </x-ui.tabela.celula>
                    <x-ui.tabela.celula>
                        {{ $this->nomes[$log->fk_c_cliente] ?? 'Cliente #'.$log->fk_c_cliente }}
                    </x-ui.tabela.celula>
                    <x-ui.tabela.celula>{{ \App\Livewire\Ferramentas::decodificarLog($log->log_query) }}</x-ui.tabela.celula>
                </x-ui.tabela.linha>
            @endforeach
        </x-ui.tabela>
        <p class="mt-2 text-xs text-on-surface-variant">Mostra as {{ \App\Livewire\Ferramentas::LIMITE_LOGS }} execuções mais recentes.</p>
    </x-ui.card>
</div>
