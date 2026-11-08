<div class="flex flex-col gap-3" @if (! $pausado) wire:poll.10s="atualizar" @endif>
    @if (! $ligado)
        <x-ui.aviso>
            O robô de contingências está desligado
            ({{ collect([$permissaoEnv ? null : '.env desligado', $ativoLocal ? null : 'painel desligado'])->filter()->join(' e ') }}):
            os números abaixo são da última coleta e nada daqui encosta nos servidores dos clientes.
        </x-ui.aviso>
    @endif

    @if (! $this->dadosDoHelp['ok'])
        <x-ui.aviso tipo="erro">O Help Desk (192.168.10.254) não respondeu. Os números abaixo são da última coleta.</x-ui.aviso>
    @endif

    @php
        $resumo = $this->resumo;
    @endphp
    <x-ui.card>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-2">
                <x-ui.chip :ativo="$situacao === ''" :contagem="$resumo['total']" wire:click="$set('situacao', '')">Todos</x-ui.chip>
                <x-ui.chip :ativo="$situacao === 'ok'" :contagem="$resumo['ok']" wire:click="alternarSituacao('ok')">OK</x-ui.chip>
                <x-ui.chip :ativo="$situacao === 'contingencia'" :contagem="$resumo['contingencia']" wire:click="alternarSituacao('contingencia')">Tem contingência</x-ui.chip>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <span class="text-sm text-on-surface-variant">{{ count($this->visiveis) }} de {{ $resumo['total'] }} clientes</span>
                <x-ui.botao variante="contorno" wire:click="alternarPoll" :icone="$pausado ? 'play_arrow' : 'pause'">
                    {{ $pausado ? 'Pausado' : 'Atualizando a cada 10 s' }}
                </x-ui.botao>
                <span class="text-xs text-on-surface-variant" title="Última atualização da tela">Atualizado às {{ $atualizadoEm }}</span>
            </div>
        </div>

        <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2 lg:grid-cols-3">
            <div class="md:col-span-2 lg:col-span-2">
                <x-ui.busca placeholder="Buscar por nome, CNPJ ou ID" wire:model.live.debounce.250ms="busca" />
            </div>
            <div>
                @if ($this->dadosDoHelp['ok'])
                    <x-ui.segmentado wire:model.live="tipo" :opcoes="['S' => 'Kits automáticos: sim', 'N' => 'Kits automáticos: não', '' => 'Todos']" />
                @else
                    <x-ui.segmentado wire:model.live="tipo" disabled class="opacity-60" :opcoes="['S' => 'Kits automáticos: sim', 'N' => 'Kits automáticos: não', '' => 'Todos']" />
                    <p class="mt-1 text-xs text-on-surface-variant">Filtro de kits indisponível sem o Help Desk.</p>
                @endif
            </div>
        </div>
    </x-ui.card>

    @if ($this->painel->isEmpty())
        <x-ui.vazio icone="smart_toy" titulo="Nenhuma coleta do robô ainda">
            O painel começa a ser preenchido quando o robô concluir a primeira coleta de um cliente.
        </x-ui.vazio>
    @elseif (empty($this->visiveis))
        <div class="flex flex-col items-center gap-3">
            <x-ui.vazio icone="search_off" titulo="Nenhum cliente com estes filtros">
                Tente mudar a busca, a situação ou o tipo de kit automático.
            </x-ui.vazio>
            <x-ui.botao variante="tonal" wire:click="limparFiltros" icone="filter_alt_off">Limpar filtros</x-ui.botao>
        </div>
    @else
        <div class="grid grid-cols-[repeat(auto-fill,minmax(15rem,1fr))] gap-2">
            @foreach ($this->visiveis as $cliente)
                @php
                    $temContingencia = $cliente['totalCliente'] > 0;
                    $url = route('relatorios', ['aba' => 'contingencia', 'cliente' => $cliente['id']]);
                @endphp
                <a href="{{ $url }}" wire:navigate wire:key="card-{{ $cliente['id'] }}" class="group block min-w-0 rounded-lg focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                    {{-- Compacto: px/py sobrepõem o p-4 do card para caber mais cards por linha. --}}
                    <x-ui.card class="flex h-full flex-col gap-2 px-3 py-2.5 transition group-hover:bg-surface-container-highest">
                        <div class="min-w-0">
                            <h3 class="truncate text-sm font-medium text-on-surface group-hover:underline" title="{{ $cliente['nome'] }}">{{ $cliente['nome'] }}</h3>
                            <p class="truncate text-xs text-on-surface-variant">{{ $cliente['cnpj'] ?? 'Sem CNPJ' }} · ID: {{ $cliente['id'] }}</p>
                        </div>

                        <dl class="grid grid-cols-4 gap-1 text-center">
                            @foreach ([
                                ['Tabela ', 'Cliente', $cliente['totalCliente']],
                                ['Tabela ', 'Motivos', $cliente['totalBot']],
                                ['Total ', 'Sactus', $cliente['totalSactus']],
                                ['Total ', 'Versatus', $cliente['totalVersatus']],
                            ] as [$prefixo, $rotulo, $valor])
                                <div class="min-w-0 rounded-sm bg-surface-container-low px-1 py-1" title="{{ $prefixo.$rotulo }}">
                                    <dt class="truncate text-[11px] text-on-surface-variant"><span class="sr-only">{{ $prefixo }}</span>{{ $rotulo }}</dt>
                                    <dd class="truncate text-sm font-medium tabular-nums text-on-surface">{{ \App\Support\Numero::inteiro($valor) }}</dd>
                                </div>
                            @endforeach
                        </dl>

                        <div class="flex items-center justify-between gap-2">
                            <span class="flex min-w-0 items-center gap-1 text-[11px] text-on-surface-variant" title="Data da última coleta">
                                <x-ui.icone nome="schedule" @class([
                                    'text-[14px]',
                                    'text-primary' => $cliente['recente'],
                                    'text-error' => ! $cliente['recente'],
                                ]) />
                                <span class="truncate tabular-nums">{{ $cliente['data']->format('d/m/Y H:i') }}</span>
                            </span>
                            <x-ui.etiqueta :cor="$temContingencia ? 'erro' : 'sucesso'">
                                {{ $temContingencia ? 'Tem contingência' : 'OK' }}
                            </x-ui.etiqueta>
                        </div>
                    </x-ui.card>
                </a>
            @endforeach
        </div>
    @endif
</div>
