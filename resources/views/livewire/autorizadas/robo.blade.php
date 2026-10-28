@use('App\Support\Numero')

<div class="flex flex-col gap-3" @if (! $pedindoSenha) wire:poll.10s @endif>
    @php
        $atual = $this->cicloAtual;
        // O bloqueio da Sefaz vem primeiro: mesmo com o robô desligado ele trava a consulta à mão
        [$estado, $icone, $cores, $detalhe] = match (true) {
            $pausa !== null && $pausa['automatica'] => ['Pausado pela Sefaz', 'block', 'bg-error-container text-on-error-container',
                $pausa['motivo'].'. Volta sozinho às '.$pausa['ate']->format('H:i').'.'],
            ! $ligado => ['Desligado neste servidor', 'power_settings_new', 'bg-surface-container-highest text-on-surface-variant',
                'Para ligar: WALLE_AUTORIZADAS_ATIVO=true no .env. Desligado, ele não consulta a Sefaz.'],
            $pausa !== null => ['Pausado', 'pause_circle', 'bg-secondary-container text-on-secondary-container',
                $pausa['motivo'].' até '.$pausa['ate']->format('d/m H:i').'.'],
            $atual !== null => ['Rodando um ciclo', 'sync', 'bg-success-container text-on-success-container',
                'Ciclo #'.$atual->id.' começou às '.$atual->iniciado_em->format('H:i:s').'.'],
            default => ['Ligado, esperando o próximo ciclo', 'check_circle', 'bg-success-container text-on-success-container',
                'O agendador confere a cada minuto e dispara um ciclo quando o anterior termina.'],
        };
    @endphp

    {{-- Estado do robô e ações --}}
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
                    <x-ui.botao variante="tonal" icone="play_arrow" wire:click="retomar"
                        :wire:confirm="$pausa['automatica'] ? 'A Sefaz bloqueou por consumo indevido. Retomar antes da hora pode bloquear de novo. Retomar mesmo assim?' : null">
                        Retomar
                    </x-ui.botao>
                @else
                    <div class="relative" x-data="{ aberto: false }" x-on:click.outside="aberto = false" x-on:keydown.escape="aberto = false">
                        <x-ui.botao variante="contorno" icone="pause" x-on:click="aberto = ! aberto" x-bind:aria-expanded="aberto" aria-haspopup="menu">Pausar</x-ui.botao>
                        <div class="absolute right-0 z-30 mt-1 w-36 rounded-sm bg-surface-container-highest py-1 shadow-lg shadow-black/40" role="menu" x-show="aberto" x-cloak>
                            @foreach (\App\Livewire\Autorizadas\Robo::PAUSAS_HORAS as $horas)
                                <button type="button" role="menuitem" class="block w-full px-4 py-2 text-left text-sm text-on-surface hover:bg-on-surface/8"
                                    wire:click="pausar({{ $horas }})" x-on:click="aberto = false">Por {{ $horas }} h</button>
                            @endforeach
                        </div>
                    </div>
                @endif

                <x-ui.botao icone="play_circle" wire:click="rodarAgora" wire:loading.attr="disabled" wire:target="rodarAgora"
                    :disabled="! $ligado || $pausa !== null || $atual !== null">Rodar agora</x-ui.botao>
            </div>
        @endif
    </section>

    @if ($aviso)
        <x-ui.aviso :tipo="$avisoErro ? 'erro' : 'sucesso'" wire:key="aviso-{{ md5($aviso) }}">{{ $aviso }}</x-ui.aviso>
    @endif

    {{-- Ciclo em andamento --}}
    @if ($atual !== null)
        @php $feito = $atual->na_fila > 0 ? min(100, $atual->consultadas / $atual->na_fila * 100) : 0; @endphp
        <section class="rounded-lg bg-surface-container-low p-4" aria-label="Ciclo em andamento">
            <div class="flex flex-wrap items-baseline justify-between gap-2 text-sm">
                <p class="text-on-surface">
                    @if ($atual->na_fila > 0)
                        <strong class="font-medium">{{ Numero::inteiro($atual->consultadas) }}</strong> de {{ Numero::inteiro($atual->na_fila) }} consultadas
                        · {{ Numero::inteiro($atual->autorizadas) }} autorizadas
                    @else
                        Preparando a fila (limpeza e semeadura)…
                    @endif
                </p>
                <p class="text-xs text-on-surface-variant">o número anda a cada {{ config('walle.autorizadas.gravar_a_cada') }} chaves gravadas</p>
            </div>
            <div class="mt-2 h-2 overflow-hidden rounded-full bg-grafico-grade" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ round($feito) }}">
                <div class="h-full rounded-full bg-grafico-1 transition-[width] duration-700" style="width: {{ $feito }}%"></div>
            </div>
        </section>
    @endif

    {{-- Números da base --}}
    @php $g = $this->geral; @endphp
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 2xl:grid-cols-6">
        <x-ui.kpi rotulo="Na fila agora" icone="pending_actions" :valor="$g['fila']"
            :detalhe="'de GO, sem consulta há '.config('walle.autorizadas.intervalo_horas').' h'" />
        <x-ui.kpi rotulo="Pendentes" icone="hourglass_top" :valor="$g['pendentes']"
            :detalhe="$g['outras_uf'] > 0 ? Numero::inteiro($g['outras_uf']).' de outras UFs' : 'ainda não autorizadas'"
            :title="$g['outras_uf'] > 0 ? 'O robô só consulta na Sefaz GO: as de outras UFs ficam pendentes.' : null" />
        <x-ui.kpi rotulo="Autorizadas" icone="verified" :valor="$g['autorizadas']" :detalhe="Numero::percentual($g['taxa']).' da base'" />
        <x-ui.kpi rotulo="Consultadas hoje" icone="today" :valor="$g['hoje']" :detalhe="Numero::inteiro($g['hora']).' na última hora'" />
        <x-ui.kpi rotulo="Nunca consultadas" icone="new_releases" :valor="$g['nunca']" detalhe="entram primeiro na fila" />
        <x-ui.kpi rotulo="Na base" icone="database" :valor="$g['total']" :detalhe="Numero::inteiro($g['clientes']).' clientes'" />
    </div>

    <p class="-mt-1 px-1 text-xs text-on-surface-variant">
        Última consulta gravada na base: {{ $g['ultima'] ? \Illuminate\Support\Carbon::parse($g['ultima'])->format('d/m/Y H:i:s') : 'nenhuma' }}
        · os números se atualizam sozinhos.
    </p>

    {{-- Consultas por hora --}}
    @php
        $h = $this->porHora;
        $temConsultas = array_sum($h['autorizadas']) + array_sum($h['outras']) > 0;
    @endphp
    <x-grafico.cartao titulo="Consultas por hora" descricao="Últimas 24 horas, somando os ciclos do robô (as falhas ficam na tabela de ciclos)."
        :legenda="[['nome' => 'Voltaram autorizadas', 'cor' => 'grafico-1'], ['nome' => 'Ainda não autorizadas', 'cor' => 'grafico-2']]">
        @if ($temConsultas)
            <x-grafico.colunas :rotulos="$h['rotulos']" :rotulo-a-cada="3" :altura="160" :series="[
                ['nome' => 'Autorizadas', 'cor' => 'grafico-1', 'valores' => $h['autorizadas']],
                ['nome' => 'Ainda não', 'cor' => 'grafico-2', 'valores' => $h['outras']],
            ]" />
        @else
            <x-ui.vazio icone="hourglass_empty" titulo="Nenhuma consulta nas últimas 24 h" class="py-8">
                {{ $ligado ? 'Os ciclos aparecem aqui assim que o robô rodar.' : 'O robô está desligado neste servidor.' }}
            </x-ui.vazio>
        @endif

        <x-slot:tabela>
            <x-grafico.tabela :colunas="['Hora', 'Autorizadas', 'Ainda não']"
                :linhas="array_map(fn ($r, $a, $o) => [$r, Numero::inteiro($a), Numero::inteiro($o)], $h['rotulos'], $h['autorizadas'], $h['outras'])" />
        </x-slot:tabela>
    </x-grafico.cartao>

    {{-- Últimos ciclos --}}
    <section class="rounded-lg bg-surface-container-low p-4" aria-label="Últimos ciclos">
        <h3 class="font-medium text-on-surface">Últimos ciclos</h3>

        @if ($this->ultimosCiclos->isEmpty())
            <x-ui.vazio icone="history" titulo="Nenhum ciclo ainda" class="py-8">O histórico dos ciclos do walle começa no primeiro que rodar.</x-ui.vazio>
        @else
            <div class="mt-2 overflow-x-auto">
                <table class="w-full min-w-[720px] text-sm">
                    <thead>
                        <tr class="text-xs text-on-surface-variant">
                            @foreach (['Ciclo', 'Início', 'Duração', 'Removidas', 'Semeadas', 'Na fila', 'Consultadas', 'Autorizadas', 'Falhas', 'Situação'] as $i => $coluna)
                                <th scope="col" @class(['border-b border-outline-variant px-2 py-1.5 font-medium', 'text-left' => $i < 2 || $i === 9, 'text-right' => $i >= 2 && $i < 9])>{{ $coluna }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->ultimosCiclos as $ciclo)
                            @php
                                $segundos = $ciclo->duracaoEmSegundos();
                                [$situacao, $cor, $dica] = match (true) {
                                    $ciclo->terminado_em === null && $ciclo->iniciado_em->gte(now()->subMinutes(\App\Livewire\Autorizadas\Robo::MINUTOS_CICLO_INTERROMPIDO)) => ['Em andamento', 'primaria', null],
                                    $ciclo->terminado_em === null => ['Interrompido', 'erro', 'Não terminou: o worker parou no meio do ciclo.'],
                                    $ciclo->erro !== null => ['Erro', 'erro', $ciclo->erro],
                                    $ciclo->consumo_indevido => ['Consumo indevido', 'erro', 'A Sefaz respondeu 656 e o robô parou.'],
                                    $ciclo->falhas > 0 => ['Com falhas', 'secundaria', 'Algumas chaves não foram consultadas (erro de comunicação ou chave inválida).'],
                                    default => ['OK', 'sucesso', null],
                                };
                            @endphp
                            <tr class="border-b border-outline-variant/50 last:border-0" wire:key="ciclo-{{ $ciclo->id }}">
                                <td class="px-2 py-1.5 text-on-surface-variant">#{{ $ciclo->id }}</td>
                                <td class="px-2 py-1.5 whitespace-nowrap text-on-surface">{{ $ciclo->iniciado_em->format('d/m H:i:s') }}</td>
                                <td class="px-2 py-1.5 text-right whitespace-nowrap text-on-surface-variant tabular-nums">
                                    {{ $segundos === null ? '—' : ($segundos >= 60 ? intdiv($segundos, 60).' min '.($segundos % 60).' s' : $segundos.' s') }}
                                </td>
                                @foreach (['removidas', 'semeadas', 'na_fila', 'consultadas', 'autorizadas', 'falhas'] as $campo)
                                    <td class="px-2 py-1.5 text-right text-on-surface-variant tabular-nums">{{ Numero::inteiro($ciclo->{$campo}) }}</td>
                                @endforeach
                                <td class="px-2 py-1.5">
                                    <x-ui.etiqueta :cor="$cor" :title="$dica">{{ $situacao }}</x-ui.etiqueta>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <x-ui.confirmar-senha :aberta="$pedindoSenha" />
</div>
