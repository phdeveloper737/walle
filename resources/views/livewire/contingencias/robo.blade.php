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
                    <x-ui.tabela.celula>
                        {{ $item->erro ?: '—' }}
                        @if ($admin && $item->status === 'falha' && ! empty($item->detalhe_tecnico))
                            @php
                                $d = $item->detalhe_tecnico;
                                $t = $d['tentativa'] ?? [];
                                $etapas = ['cadastro' => 'Cadastro no Help Desk', 'porta' => 'Teste da porta', 'login' => 'Entrada no MySQL', 'banco_actus' => 'Busca do banco do Sactus'];
                                $emSegundos = fn ($v) => $v === null ? null : $v.' s';
                                $secoes = [
                                    'Erro' => [
                                        'Código' => $d['codigo'] ?? null,
                                        'Classe' => $d['classe'] ?? null,
                                        'Mensagem original' => $d['mensagem'] ?? null,
                                        'Detalhe' => $d['detalhe'] ?? null,
                                        'Origem' => $d['origem'] ?? null,
                                    ],
                                    'Tentativa de conexão' => [
                                        'Etapa' => isset($t['etapa']) ? ($etapas[$t['etapa']] ?? $t['etapa']) : null,
                                        'Servidor' => isset($t['host']) ? $t['host'].(isset($t['porta']) ? ':'.$t['porta'] : '') : null,
                                        'Usuário' => $t['usuario'] ?? null,
                                        'Banco do cadastro' => $t['banco_cadastro'] ?? null,
                                        'Local do servidor' => $t['local_servidor'] ?? null,
                                        'Tempo limite da porta' => $emSegundos($t['timeout_porta_segundos'] ?? null),
                                        'Tempo limite do MySQL' => $emSegundos($t['timeout_pdo_segundos'] ?? null),
                                        'Socket' => isset($t['socket']) ? trim(($t['socket']['codigo'] ?? '').' '.($t['socket']['mensagem'] ?? '')) : null,
                                        'Bancos encontrados' => ! empty($t['bancos_encontrados']) ? implode(', ', $t['bancos_encontrados']) : null,
                                        'Candidatos do Sactus' => ! empty($t['candidatos_actus']) ? implode(', ', $t['candidatos_actus']) : null,
                                    ],
                                ];
                                $erros = $t['erros'] ?? [];
                                $anteriores = $d['anteriores'] ?? [];
                            @endphp
                            <details class="mt-1 text-xs" wire:key="detalhe-{{ $item->id }}">
                                <summary class="cursor-pointer rounded-sm text-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">Detalhes técnicos</summary>
                                <div class="mt-1 flex flex-col gap-2 rounded-sm bg-surface-container-low p-2 text-on-surface">
                                    @foreach ($secoes as $titulo => $campos)
                                        @php $campos = array_filter($campos, fn ($v) => $v !== null && $v !== ''); @endphp
                                        @if ($campos)
                                            <div>
                                                <p class="font-medium text-on-surface-variant">{{ $titulo }}</p>
                                                <dl class="grid grid-cols-[auto_1fr] gap-x-2">
                                                    @foreach ($campos as $rotulo => $valor)
                                                        <dt class="text-on-surface-variant">{{ $rotulo }}</dt>
                                                        <dd class="font-mono break-words whitespace-pre-wrap">{{ $valor }}</dd>
                                                    @endforeach
                                                </dl>
                                            </div>
                                        @endif
                                    @endforeach
                                    @if ($erros)
                                        <div>
                                            <p class="font-medium text-on-surface-variant">Tentativas de entrada no MySQL</p>
                                            <ul class="flex flex-col gap-1">
                                                @foreach ($erros as $e)
                                                    <li class="font-mono break-words whitespace-pre-wrap">{{ $e['banco'] ?? '—' }} · {{ $e['classe'] ?? '' }} · {{ $e['codigo'] ?? '' }} · {{ $e['mensagem'] ?? '' }}</li>
                                                @endforeach
                                            </ul>
                                        </div>
                                    @endif
                                    @if ($anteriores)
                                        <div>
                                            <p class="font-medium text-on-surface-variant">Exceções anteriores</p>
                                            <ul class="flex flex-col gap-1">
                                                @foreach ($anteriores as $a)
                                                    <li class="font-mono break-words whitespace-pre-wrap">{{ $a['classe'] ?? '' }} · {{ $a['mensagem'] ?? '' }}</li>
                                                @endforeach
                                            </ul>
                                        </div>
                                    @endif
                                </div>
                            </details>
                        @endif
                    </x-ui.tabela.celula>
                </x-ui.tabela.linha>
            @endforeach
        </x-ui.tabela>
        <x-slot:rodape>
            <x-ui.botao variante="texto" wire:click="fecharColetas">Fechar</x-ui.botao>
        </x-slot:rodape>
    </x-ui.modal>
</div>
