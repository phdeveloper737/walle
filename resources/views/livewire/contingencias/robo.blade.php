<div class="flex flex-col gap-3">
    @if (! $ligado)
        <x-ui.aviso>
            O robô de contingências está <strong>desligado</strong>
            ({{ collect([$permissaoEnv ? null : '.env desligado', $ativoLocal ? null : 'painel desligado'])->filter()->join(' e ') }}):
            os números abaixo são da última coleta e nada daqui encosta nos servidores dos clientes.
        </x-ui.aviso>
    @endif

    @if ($this->nomes === null)
        <x-ui.aviso tipo="erro">O Help Desk (192.168.10.254) não respondeu. Os nomes dos clientes não aparecem; os números são da última coleta.</x-ui.aviso>
    @endif

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <x-ui.kpi rotulo="Clientes acompanhados" :valor="$this->resumo['clientes']" icone="storefront" />
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
                @php
                    $id = $linha['cliente'];
                    $painel = $linha['painel'];
                    $coleta = $linha['coleta'];
                    [$cor, $texto] = match ($coleta?->status) {
                        'falha' => ['erro', 'Falhou'],
                        'sucesso' => ['sucesso', 'Coletado'],
                        default => ['neutra', 'Em andamento'],
                    };
                @endphp
                <x-ui.tabela.linha wire:key="coleta-{{ $id }}">
                    <x-ui.tabela.celula>
                        {{ $this->nomes[$id] ?? 'Cliente #'.$id }}
                        <span class="text-xs text-on-surface-variant">#{{ $id }}</span>
                    </x-ui.tabela.celula>
                    <x-ui.tabela.celula numero destaque>{{ $painel?->total_cliente ?? '—' }}</x-ui.tabela.celula>
                    <x-ui.tabela.celula numero>{{ $painel?->total_sactus ?? '—' }}</x-ui.tabela.celula>
                    <x-ui.tabela.celula numero>{{ $painel?->total_versatus ?? '—' }}</x-ui.tabela.celula>
                    <x-ui.tabela.celula>{{ ($coleta?->terminado_em ?? $painel?->data)?->format('d/m/Y H:i') ?? '—' }}</x-ui.tabela.celula>
                    <x-ui.tabela.celula>
                        @if ($coleta)
                            <button type="button" wire:click="verColetas({{ $id }})" title="Ver as últimas coletas deste cliente"
                                class="rounded-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                                <x-ui.etiqueta :cor="$cor" class="cursor-pointer hover:brightness-110">{{ $texto }}</x-ui.etiqueta>
                            </button>
                        @else
                            <x-ui.etiqueta>Sem coleta no histórico</x-ui.etiqueta>
                        @endif
                    </x-ui.tabela.celula>
                </x-ui.tabela.linha>
            @endforeach
        </x-ui.tabela>
    </x-ui.card>

    <x-ui.modal :aberta="$this->clienteColetas !== null" :titulo="$this->tituloColetas" icone="history" fechar="fecharColetas" largura="760px">
        <x-ui.tabela vazio="Nenhuma coleta deste cliente" vazioIcone="history" largura="560px">
            <x-slot:explicacao>O histórico deste cliente começa na primeira coleta que o robô fizer.</x-slot:explicacao>
            <x-slot:cabecalho>
                <x-ui.tabela.coluna>Início</x-ui.tabela.coluna>
                <x-ui.tabela.coluna>Duração</x-ui.tabela.coluna>
                <x-ui.tabela.coluna>Situação</x-ui.tabela.coluna>
                <x-ui.tabela.coluna>Erro</x-ui.tabela.coluna>
            </x-slot:cabecalho>
            @foreach ($this->coletasDoCliente as $item)
                @php
                    $segundos = $item->terminado_em ? (int) abs($item->terminado_em->diffInSeconds($item->iniciado_em)) : null;
                    [$corItem, $textoItem] = match ($item->status) {
                        'falha' => ['erro', 'Falhou'],
                        'sucesso' => ['sucesso', 'Coletado'],
                        default => ['neutra', 'Em andamento'],
                    };
                @endphp
                <x-ui.tabela.linha wire:key="historico-{{ $item->id }}">
                    <x-ui.tabela.celula>{{ $item->iniciado_em->format('d/m/Y H:i:s') }}</x-ui.tabela.celula>
                    <x-ui.tabela.celula>{{ $segundos === null ? '—' : ($segundos < 60 ? $segundos.' s' : intdiv($segundos, 60).' min '.($segundos % 60).' s') }}</x-ui.tabela.celula>
                    <x-ui.tabela.celula><x-ui.etiqueta :cor="$corItem">{{ $textoItem }}</x-ui.etiqueta></x-ui.tabela.celula>
                    <x-ui.tabela.celula>{{ $item->erro ?: '—' }}</x-ui.tabela.celula>
                </x-ui.tabela.linha>
            @endforeach
        </x-ui.tabela>
        <x-slot:rodape>
            <x-ui.botao variante="texto" wire:click="fecharColetas">Fechar</x-ui.botao>
        </x-slot:rodape>
    </x-ui.modal>
</div>
