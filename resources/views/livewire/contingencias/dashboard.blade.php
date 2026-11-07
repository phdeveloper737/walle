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
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
            @foreach ($this->visiveis as $cliente)
                @php
                    $temContingencia = $cliente['totalCliente'] > 0;
                    $url = route('relatorios', ['aba' => 'contingencia', 'cliente' => $cliente['id']]);
                @endphp
                <a href="{{ $url }}" wire:navigate wire:key="card-{{ $cliente['id'] }}" class="group block rounded-lg focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                    <x-ui.card class="flex h-full flex-col justify-between transition group-hover:bg-surface-container-highest">
                    <div class="flex flex-col gap-3">
                        <div class="flex items-start justify-between gap-2">
                            <div>
                                <h3 class="font-medium text-on-surface group-hover:underline">{{ $cliente['nome'] }}</h3>
                                <p class="text-xs text-on-surface-variant">{{ $cliente['cnpj'] ?? 'CNPJ não cadastrado' }} · ID: {{ $cliente['id'] }}</p>
                            </div>
                            <x-ui.etiqueta :cor="$temContingencia ? 'erro' : 'sucesso'">
                                {{ $temContingencia ? 'Tem contingência' : 'OK' }}
                            </x-ui.etiqueta>
                        </div>

                        <div class="grid grid-cols-2 gap-2 text-sm text-on-surface-variant">
                            <div class="flex items-center gap-1.5">
                                <x-ui.icone nome="receipt_long" class="text-[18px] text-secondary" />
                                <span>Tabela Cliente: <strong class="text-on-surface">{{ \App\Support\Numero::inteiro($cliente['totalCliente']) }}</strong></span>
                            </div>
                            <div class="flex items-center gap-1.5">
                                <x-ui.icone nome="warning" class="text-[18px] text-secondary" />
                                <span>Tabela Motivos: <strong class="text-on-surface">{{ \App\Support\Numero::inteiro($cliente['totalBot']) }}</strong></span>
                            </div>
                            <div class="flex items-center gap-1.5">
                                <x-ui.icone nome="dns" class="text-[18px] text-secondary" />
                                <span>Total Sactus: <strong class="text-on-surface">{{ \App\Support\Numero::inteiro($cliente['totalSactus']) }}</strong></span>
                            </div>
                            <div class="flex items-center gap-1.5">
                                <x-ui.icone nome="storage" class="text-[18px] text-secondary" />
                                <span>Total Versatus: <strong class="text-on-surface">{{ \App\Support\Numero::inteiro($cliente['totalVersatus']) }}</strong></span>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 flex items-center gap-1.5 border-t border-outline-variant pt-3 text-xs text-on-surface-variant">
                        <x-ui.icone nome="schedule" @class([
                            'text-[16px]',
                            'text-primary' => $cliente['recente'],
                            'text-error' => ! $cliente['recente'],
                        ]) />
                        <span>Coletado em {{ $cliente['data']->format('d/m/Y H:i') }}</span>
                    </div>
                    </x-ui.card>
                </a>
            @endforeach
        </div>
    @endif
</div>
