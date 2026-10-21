@use('App\Support\Numero')

{{-- Sem atualizar sozinho enquanto a janela da senha estiver aberta --}}
<div class="flex flex-col gap-3" @if (! $pedindoSenha) wire:poll.15s @endif>
    @php
        $atual = $this->cicloAtual;
        [$estado, $icone, $cores, $detalhe] = match (true) {
            ! $ligado => ['Desligado neste servidor', 'power_settings_new', 'bg-surface-container-highest text-on-surface-variant',
                'Para ligar: WALLE_SACTUS_ATIVO=true no .env, na virada (junto com o desligamento do sactus.php no 220).'],
            $pausa !== null => ['Pausado', 'pause_circle', 'bg-secondary-container text-on-secondary-container', $pausa['motivo'].' até '.$pausa['ate']->format('d/m H:i').'.'],
            $atual !== null => ['Rodando um ciclo', 'sync', 'bg-success-container text-on-success-container', 'Ciclo #'.$atual->id.' começou às '.$atual->iniciado_em->format('H:i:s').'.'],
            default => ['Ligado, esperando a próxima hora cheia', 'check_circle', 'bg-success-container text-on-success-container', 'Um ciclo por hora; cada cliente é processado uma vez por ciclo.'],
        };
    @endphp

    <section class="flex flex-col gap-3 rounded-lg bg-surface-container-high p-4 md:flex-row md:items-center" aria-label="Estado do robô">
        <div class="flex min-w-0 items-start gap-3">
            <span class="flex size-10 shrink-0 items-center justify-center rounded-full {{ $cores }}">
                <x-ui.icone :nome="$icone" class="text-[22px]" />
            </span>
            <div class="min-w-0">
                <h2 class="font-medium text-on-surface">{{ $estado }}</h2>
                <p class="mt-0.5 text-sm text-on-surface-variant">{{ $detalhe }}</p>
            </div>
        </div>

        @if ($admin && $ligado)
            <div class="flex flex-wrap items-center gap-2 md:ml-auto">
                @if ($pausa !== null)
                    <x-ui.botao variante="tonal" icone="play_arrow" wire:click="retomar">Retomar</x-ui.botao>
                @else
                    <div class="relative" x-data="{ aberto: false }" x-on:click.outside="aberto = false" x-on:keydown.escape="aberto = false">
                        <x-ui.botao variante="contorno" icone="pause" x-on:click="aberto = ! aberto" x-bind:aria-expanded="aberto" aria-haspopup="menu">Pausar</x-ui.botao>
                        <div class="absolute right-0 z-30 mt-1 w-36 rounded-sm bg-surface-container-highest py-1 shadow-lg shadow-black/40" role="menu" x-show="aberto" x-cloak>
                            @foreach (\App\Livewire\Sactus\Robo::PAUSAS_HORAS as $horas)
                                <button type="button" role="menuitem" class="block w-full px-4 py-2 text-left text-sm text-on-surface hover:bg-on-surface/8"
                                    wire:click="pausar({{ $horas }})" x-on:click="aberto = false">Por {{ $horas }} h</button>
                            @endforeach
                        </div>
                    </div>
                @endif
                <x-ui.botao icone="play_circle" wire:click="rodarAgora" :disabled="$pausa !== null || $atual !== null"
                    wire:confirm="Rodar um ciclo agora? Entra por SSH em todos os servidores da coleta (e atualiza o ERP de quem está marcado no Help Desk).">Rodar agora</x-ui.botao>
            </div>
        @endif
    </section>

    @if ($aviso)
        <x-ui.aviso :tipo="$avisoErro ? 'erro' : 'sucesso'" wire:key="aviso-{{ md5($aviso) }}">{{ $aviso }}</x-ui.aviso>
    @endif

    @if (! $erpConfigurado)
        <x-ui.aviso tipo="erro">
            Atualização do ERP sem a senha do 220 (SACTUS_ERP_SENHA no .env): com o robô ligado, os clientes com atualizar_erp = 'S' não seriam atualizados.
        </x-ui.aviso>
    @endif

    {{-- O que ele faz, lido do Help Desk --}}
    <section aria-label="O que o robô faz">
        <h3 class="mb-2 px-1 text-sm font-medium text-on-surface">A cada ciclo, pelo cadastro do Help Desk</h3>
        @if ($p = $this->plano)
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 2xl:grid-cols-6">
                <x-ui.kpi rotulo="Servidores na coleta" icone="dns" :valor="$p['elegiveis']" detalhe="lê o /etc/sactus.cfg" />
                <x-ui.kpi rotulo="Docker" icone="deployed_code" :valor="$p['docker']" detalhe="pasta_multiempresa = S" />
                <x-ui.kpi rotulo="Servidor na empresa" icone="apartment" :valor="$p['empresa']" detalhe="local_servidor = EMPRESA" />
                <x-ui.kpi rotulo="Com ACL" icone="lock" :valor="$p['acl']" detalhe="service_acl = S" />
                <x-ui.kpi rotulo="Atualizam o ERP" icone="system_update" :valor="$p['erp']" detalhe="rsync de /var/sistema" />
                <x-ui.kpi rotulo="ERP pela VPN" icone="vpn_lock" :valor="$p['erp_vpn']" detalhe="só pelo IP 10.8.0.x" />
            </div>
        @else
            <x-ui.aviso tipo="erro">Não foi possível consultar o Help Desk (192.168.10.254) agora.</x-ui.aviso>
        @endif
    </section>

    {{-- Últimos ciclos --}}
    <section class="rounded-lg bg-surface-container-low p-4" aria-label="Últimos ciclos">
        <h3 class="font-medium text-on-surface">Últimos ciclos</h3>

        @if ($this->ultimosCiclos->isEmpty())
            <x-ui.vazio icone="history" titulo="Nenhum ciclo ainda" class="py-8">O histórico dos ciclos do walle começa no primeiro que rodar.</x-ui.vazio>
        @else
            <div class="mt-2 overflow-x-auto">
                <table class="w-full min-w-[820px] text-sm">
                    <thead>
                        <tr class="text-xs text-on-surface-variant">
                            @foreach (['Ciclo', 'Início', 'Duração', 'Na coleta', 'Coletados', 'Fora do ar', 'Falhas', 'ACL ok/falha', 'ERP ok/falha', 'Pedidos ok/erro', 'Situação'] as $i => $coluna)
                                <th scope="col" @class(['border-b border-outline-variant px-2 py-1.5 font-medium', 'text-left' => $i < 2 || $i === 10, 'text-right' => $i >= 2 && $i < 10])>{{ $coluna }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->ultimosCiclos as $c)
                            @php
                                $s = $c->duracaoEmSegundos();
                                [$situacao, $cor] = match (true) {
                                    $c->terminado_em === null && $atual?->id === $c->id => ['Em andamento', 'primaria'],
                                    $c->terminado_em === null => ['Interrompido', 'erro'],
                                    $c->erro !== null => ['Erro', 'erro'],
                                    $c->falhas + $c->acl_falha + $c->erp_falha + $c->cfg_falha > 0 => ['Com falhas', 'secundaria'],
                                    default => ['OK', 'sucesso'],
                                };
                            @endphp
                            <tr class="border-b border-outline-variant/50 last:border-0" wire:key="ciclo-{{ $c->id }}">
                                <td class="px-2 py-1.5 text-on-surface-variant">#{{ $c->id }}</td>
                                <td class="px-2 py-1.5 whitespace-nowrap text-on-surface">{{ $c->iniciado_em->format('d/m H:i:s') }}</td>
                                <td class="px-2 py-1.5 text-right whitespace-nowrap text-on-surface-variant tabular-nums">{{ $s === null ? '—' : intdiv($s, 60).' min '.($s % 60).' s' }}</td>
                                <td class="px-2 py-1.5 text-right text-on-surface-variant tabular-nums">{{ Numero::inteiro($c->elegiveis) }}</td>
                                <td class="px-2 py-1.5 text-right text-on-surface-variant tabular-nums">{{ Numero::inteiro($c->coletados) }}</td>
                                <td class="px-2 py-1.5 text-right text-on-surface-variant tabular-nums">{{ Numero::inteiro($c->offline) }}</td>
                                <td class="px-2 py-1.5 text-right text-on-surface-variant tabular-nums">{{ Numero::inteiro($c->falhas + $c->sem_credenciais) }}</td>
                                <td class="px-2 py-1.5 text-right text-on-surface-variant tabular-nums">{{ $c->acl_ok }}/{{ $c->acl_falha }}</td>
                                <td class="px-2 py-1.5 text-right text-on-surface-variant tabular-nums">{{ $c->erp_ok }}/{{ $c->erp_falha }}</td>
                                <td class="px-2 py-1.5 text-right text-on-surface-variant tabular-nums">{{ $c->cfg_ok }}/{{ $c->cfg_falha }}</td>
                                <td class="px-2 py-1.5"><x-ui.etiqueta :cor="$cor" :title="$c->erro">{{ $situacao }}</x-ui.etiqueta></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <x-ui.confirmar-senha :aberta="$pedindoSenha" />
</div>
