<div class="flex flex-col gap-3">
    @if (! $ligado)
        <x-ui.aviso>
            O robô do Sentinela está <strong>desligado</strong> (WALLE_SENTINELA_ATIVO=false): os números abaixo são
            da última coleta e nada daqui encosta nos servidores dos clientes.
        </x-ui.aviso>
    @endif

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
        <x-ui.kpi rotulo="Servidores" :valor="$this->resumo['total']" icone="dns" />
        <x-ui.kpi rotulo="Respondendo" :valor="$this->resumo['online']" icone="wifi" />
        <x-ui.kpi rotulo="Sem resposta" :valor="$this->resumo['offline']" icone="wifi_off" />
        <x-ui.kpi rotulo="Coleta antiga" :valor="$this->resumo['obsoletos']" icone="schedule" detalhe="Mais de 30 minutos sem atualizar" />
        <x-ui.kpi rotulo="Com alerta" :valor="$this->resumo['alertas']" icone="warning" />
    </div>

    <x-ui.card titulo="Saúde dos servidores" icone="monitor_heart">
        <x-ui.tabela vazio="Nenhum cliente encontrado" vazioIcone="sensors_off" largura="900px">
            <x-slot:explicacao>
                @if ($cliente !== null)
                    Não há coleta do Sentinela para o cliente #{{ $cliente }}. Remova o filtro para ver todos os servidores.
                @else
                    A tela começa a ser preenchida quando o robô concluir a primeira coleta de um cliente.
                @endif
            </x-slot:explicacao>
            <x-slot:cabecalho>
                <x-ui.tabela.coluna>Cliente</x-ui.tabela.coluna>
                <x-ui.tabela.coluna>Situação</x-ui.tabela.coluna>
                <x-ui.tabela.coluna numero>CPU</x-ui.tabela.coluna>
                <x-ui.tabela.coluna numero>Memória</x-ui.tabela.coluna>
                <x-ui.tabela.coluna numero>Disco</x-ui.tabela.coluna>
                <x-ui.tabela.coluna>Sactus</x-ui.tabela.coluna>
                <x-ui.tabela.coluna>Última coleta</x-ui.tabela.coluna>
            </x-slot:cabecalho>
            @foreach ($this->linhas as $linha)
                @php $respondendo = $linha->status === 'S'; @endphp
                <x-ui.tabela.linha wire:key="sentinela-{{ $linha->fk_c_cliente }}">
                    <x-ui.tabela.celula>
                        {{ $this->nomes[$linha->fk_c_cliente] ?? 'Cliente #'.$linha->fk_c_cliente }}
                        <span class="text-xs text-on-surface-variant">#{{ $linha->fk_c_cliente }} {{ $linha->hostname }}</span>
                    </x-ui.tabela.celula>
                    <x-ui.tabela.celula>
                        @if ($respondendo)
                            <x-ui.etiqueta cor="sucesso">Respondendo</x-ui.etiqueta>
                            @foreach ($this->alertas($linha) as $alerta)
                                <x-ui.etiqueta cor="erro">{{ $alerta }}</x-ui.etiqueta>
                            @endforeach
                        @else
                            <x-ui.etiqueta cor="erro">Sem resposta</x-ui.etiqueta>
                            @if ($linha->erro !== '')
                                <p class="mt-1 text-xs text-on-surface-variant">{{ $linha->erro }}</p>
                            @endif
                        @endif
                    </x-ui.tabela.celula>
                    <x-ui.tabela.celula numero>{{ $respondendo && $linha->cpu_uso !== null ? $linha->cpu_uso.'%' : '—' }}</x-ui.tabela.celula>
                    <x-ui.tabela.celula numero>{{ $respondendo ? $linha->mem_pct.'%' : '—' }}</x-ui.tabela.celula>
                    <x-ui.tabela.celula numero>{{ $respondendo ? $linha->disco_pct.'%' : '—' }}</x-ui.tabela.celula>
                    <x-ui.tabela.celula>
                        @if (! $respondendo)
                            —
                        @elseif ($linha->sactus_rodando === 'S')
                            {{ $linha->sactus_versao !== '' ? $linha->sactus_versao : 'Rodando' }}
                        @elseif ($linha->sactus_rodando === 'N')
                            Parado
                        @else
                            Sem informação
                        @endif
                    </x-ui.tabela.celula>
                    <x-ui.tabela.celula>
                        {{ $linha->data->format('d/m/Y H:i') }}
                        @if ($this->obsoleta($linha))
                            <x-ui.etiqueta>Antiga</x-ui.etiqueta>
                        @endif
                    </x-ui.tabela.celula>
                </x-ui.tabela.linha>
            @endforeach
        </x-ui.tabela>
    </x-ui.card>
</div>
