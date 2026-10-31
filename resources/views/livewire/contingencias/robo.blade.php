<div class="flex flex-col gap-3">
    @if (! $ligado)
        <x-ui.aviso>
            O robô de contingências está <strong>desligado</strong> (WALLE_CONTINGENCIAS_ATIVO=true): os números abaixo são
            da última coleta e nada daqui encosta nos servidores dos clientes.
        </x-ui.aviso>
    @endif

    @if ($this->nomes === null)
        <x-ui.aviso tipo="erro">O Help Desk (192.168.10.254) não respondeu. Os nomes dos clientes não aparecem; os números são da última coleta.</x-ui.aviso>
    @endif

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <x-ui.kpi rotulo="Clientes no painel" :valor="$this->resumo['clientes']" icone="storefront" />
        <x-ui.kpi rotulo="Com contingências" :valor="$this->resumo['com']" icone="receipt_long" :detalhe="$this->resumo['total'].' contingências no total'" />
        <x-ui.kpi rotulo="Sem contingências" :valor="$this->resumo['sem']" icone="check_circle" />
        <x-ui.kpi rotulo="Última coleta com falha" :valor="$this->resumo['falhas']" icone="error" />
    </div>

    <x-ui.card titulo="Clientes e última coleta" icone="smart_toy">
        <x-ui.tabela vazio="Nenhuma coleta ainda" vazioIcone="smart_toy" largura="760px">
            <x-slot:explicacao>O painel começa a ser preenchido quando o robô concluir a primeira coleta de um cliente.</x-slot:explicacao>
            <x-slot:cabecalho>
                <x-ui.tabela.coluna>Cliente</x-ui.tabela.coluna>
                <x-ui.tabela.coluna numero>Contingências</x-ui.tabela.coluna>
                <x-ui.tabela.coluna numero>Sactus</x-ui.tabela.coluna>
                <x-ui.tabela.coluna numero>Versatus</x-ui.tabela.coluna>
                <x-ui.tabela.coluna>Última coleta</x-ui.tabela.coluna>
                <x-ui.tabela.coluna>Situação</x-ui.tabela.coluna>
            </x-slot:cabecalho>
            @foreach ($this->linhas as $linha)
                @php $coleta = $linha->ultimaColeta; @endphp
                <x-ui.tabela.linha wire:key="coleta-{{ $linha->fk_c_cliente }}">
                    <x-ui.tabela.celula>
                        {{ $this->nomes[$linha->fk_c_cliente] ?? 'Cliente #'.$linha->fk_c_cliente }}
                        <span class="text-xs text-on-surface-variant">#{{ $linha->fk_c_cliente }}</span>
                    </x-ui.tabela.celula>
                    <x-ui.tabela.celula numero destaque>{{ $linha->total_cliente ?? '—' }}</x-ui.tabela.celula>
                    <x-ui.tabela.celula numero>{{ $linha->total_sactus ?? '—' }}</x-ui.tabela.celula>
                    <x-ui.tabela.celula numero>{{ $linha->total_versatus ?? '—' }}</x-ui.tabela.celula>
                    <x-ui.tabela.celula>{{ ($coleta?->terminado_em ?? $linha->data)?->format('d/m/Y H:i') ?? '—' }}</x-ui.tabela.celula>
                    <x-ui.tabela.celula>
                        @if ($coleta?->status === 'falha')
                            <x-ui.etiqueta cor="erro">Falhou</x-ui.etiqueta>
                            <p class="mt-1 text-xs text-on-surface-variant">{{ $coleta->erro }}</p>
                        @elseif ($coleta?->status === 'sucesso')
                            <x-ui.etiqueta cor="sucesso">Coletado</x-ui.etiqueta>
                        @else
                            <x-ui.etiqueta>{{ $coleta ? 'Em andamento' : 'Sem coleta no histórico' }}</x-ui.etiqueta>
                        @endif
                    </x-ui.tabela.celula>
                </x-ui.tabela.linha>
            @endforeach
        </x-ui.tabela>
    </x-ui.card>
</div>
