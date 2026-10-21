@use('App\Support\Numero')
@use('App\Autorizadas\Periodo')

<div class="flex flex-col gap-3">
    {{-- Tendência: a base inteira, fora do filtro de período --}}
    <section class="flex flex-col gap-3" aria-label="Tendência da base">
        <div>
            <h2 class="font-medium text-on-surface">Tendência</h2>
            <p class="text-xs text-on-surface-variant">A base inteira, pela data de referência das chaves (elas ficam {{ config('walle.autorizadas.dias_retencao') }} dias).</p>
        </div>

        <div class="grid gap-3 lg:grid-cols-2 2xl:grid-cols-3">
            <x-grafico.cartao titulo="Contingências por semana" descricao="Semanas de segunda a domingo."
                :legenda="[['nome' => 'Autorizadas', 'cor' => 'grafico-1'], ['nome' => 'Pendentes', 'cor' => 'grafico-2']]">
                <x-grafico.colunas :rotulos="array_column($semanas, 'rotulo')"
                    :rodapes="array_map(fn ($s) => Numero::percentual($s['taxa']).' autorizadas', $semanas)"
                    :series="[
                        ['nome' => 'Autorizadas', 'cor' => 'grafico-1', 'valores' => array_column($semanas, 'autorizadas')],
                        ['nome' => 'Pendentes', 'cor' => 'grafico-2', 'valores' => array_column($semanas, 'pendentes')],
                    ]" />
                <x-slot:tabela>
                    <x-grafico.tabela :colunas="['Semana de', 'Autorizadas', 'Pendentes', 'Total', 'Taxa']"
                        :linhas="array_map(fn ($s) => [$s['rotulo'], Numero::inteiro($s['autorizadas']), Numero::inteiro($s['pendentes']), Numero::inteiro($s['total']), Numero::percentual($s['taxa'])], $semanas)" />
                </x-slot:tabela>
            </x-grafico.cartao>

            <x-grafico.cartao titulo="Taxa de autorização por semana" descricao="Quanto das contingências de cada semana a Sefaz já autorizou. As semanas recentes ainda estão regularizando.">
                <x-grafico.linhas :rotulos="array_column($semanas, 'rotulo')" formato="percentual" :topo="100" :marcas="[0, 25, 50, 75, 100]"
                    :series="[['nome' => 'Autorizadas', 'cor' => 'grafico-1', 'valores' => array_map(fn ($s) => $s['total'] > 0 ? $s['taxa'] : null, $semanas), 'destaque' => true]]" />
                <x-slot:tabela>
                    <x-grafico.tabela :colunas="['Semana de', 'Taxa']" :linhas="array_map(fn ($s) => [$s['rotulo'], $s['total'] > 0 ? Numero::percentual($s['taxa']) : '—'], $semanas)" />
                </x-slot:tabela>
            </x-grafico.cartao>

            <x-grafico.cartao titulo="Contingências por dia" :descricao="$diario['rotulo_atual'].' comparado com '.mb_strtolower($diario['rotulo_anterior']).', dia a dia.'"
                class="lg:col-span-2 2xl:col-span-1"
                :legenda="[['nome' => $diario['rotulo_atual'], 'cor' => 'grafico-1', 'forma' => 'linha'], ['nome' => $diario['rotulo_anterior'], 'cor' => 'grafico-contexto', 'forma' => 'linha']]">
                <x-grafico.linhas :rotulos="array_map('strval', $diario['dias'])" :rotulo-a-cada="5" :series="[
                    ['nome' => $diario['rotulo_anterior'], 'cor' => 'grafico-contexto', 'valores' => $diario['anterior']],
                    ['nome' => $diario['rotulo_atual'], 'cor' => 'grafico-1', 'valores' => $diario['atual'], 'destaque' => true],
                ]" />
                <x-slot:tabela>
                    <x-grafico.tabela :colunas="['Dia', $diario['rotulo_atual'], $diario['rotulo_anterior']]"
                        :linhas="array_map(fn ($d, $a, $b) => [$d, $a === null ? '—' : Numero::inteiro($a), $b === null ? '—' : Numero::inteiro($b)], $diario['dias'], $diario['atual'], $diario['anterior'])" />
                </x-slot:tabela>
            </x-grafico.cartao>
        </div>
    </section>

    {{-- Período: o filtro vale para tudo daqui para baixo --}}
    <section class="mt-2 flex flex-col gap-3" aria-label="Período">
        <div class="flex flex-col gap-2 lg:flex-row lg:items-center">
            {{-- No celular os 7 atalhos não cabem: a linha rola de lado em vez de espremer os textos --}}
            <div class="-mx-1 overflow-x-auto px-1 lg:mx-0 lg:overflow-visible lg:px-0">
                <x-ui.segmentado compacto class="min-w-max lg:max-w-2xl lg:min-w-0" wire:model.live="periodo" :opcoes="Periodo::ATALHOS" />
            </div>
            <p class="text-xs text-on-surface-variant lg:ml-2">
                Referência de {{ \Illuminate\Support\Carbon::parse($this->recorte->de)->format('d/m/Y') }}
                a {{ \Illuminate\Support\Carbon::parse($this->recorte->ate)->format('d/m/Y') }}
            </p>
        </div>

        <div class="flex flex-col gap-3 transition-opacity" wire:loading.class="opacity-60" wire:target="periodo, filtrarCliente">
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 2xl:grid-cols-6">
                <x-ui.kpi rotulo="Contingências" icone="receipt_long" :valor="$resumo['total']" :detalhe="$this->recorte->rotulo" />
                <x-ui.kpi rotulo="Autorizadas" icone="verified" :valor="$resumo['autorizadas']" :detalhe="Numero::percentual($resumo['taxa']).' do período'" />
                <x-ui.kpi rotulo="Pendentes" icone="hourglass_top" :valor="$resumo['pendentes']" detalhe="ainda não autorizadas" />
                <x-ui.kpi rotulo="Nunca consultadas" icone="new_releases" :valor="$resumo['nunca']" detalhe="esperando o robô" />
                <x-ui.kpi rotulo="Clientes" icone="storefront" :valor="$resumo['clientes']" detalhe="com contingência" />
                <x-ui.kpi rotulo="CNPJs" icone="badge" :valor="$resumo['cnpjs']" detalhe="emitentes distintos" />
            </div>

            @if ($resumo['total'] === 0)
                <x-ui.vazio icone="event_busy" titulo="Nenhuma contingência no período">Escolha outro período acima.</x-ui.vazio>
            @else
                <div class="grid gap-3 xl:grid-cols-2">
                    <x-grafico.cartao titulo="Clientes com mais contingências" descricao="Clique num cliente para ver as pendentes dele no fim da página."
                        :legenda="[['nome' => 'Autorizadas', 'cor' => 'grafico-1'], ['nome' => 'Pendentes', 'cor' => 'grafico-2']]">
                        @php $selecionado = array_search($clienteId, array_column($clientes, 'cliente'), true); @endphp
                        <x-grafico.barras :selecionado="$selecionado === false ? null : $selecionado"
                            :series="[['nome' => 'Autorizadas', 'cor' => 'grafico-1'], ['nome' => 'Pendentes', 'cor' => 'grafico-2']]"
                            :itens="array_map(fn ($c) => [
                                'rotulo' => $this->nomeCliente($c['cliente']),
                                'valores' => [$c['autorizadas'], $c['pendentes']],
                                'rodape' => Numero::percentual($c['taxa']).' autorizadas · #'.$c['cliente'],
                                'clique' => 'filtrarCliente('.$c['cliente'].')',
                            ], $clientes)" />
                        <x-slot:tabela>
                            <x-grafico.tabela :colunas="['Cliente', 'Autorizadas', 'Pendentes', 'Total', 'Taxa']"
                                :linhas="array_map(fn ($c) => [$this->nomeCliente($c['cliente']), Numero::inteiro($c['autorizadas']), Numero::inteiro($c['pendentes']), Numero::inteiro($c['total']), Numero::percentual($c['taxa'])], $clientes)" />
                        </x-slot:tabela>
                    </x-grafico.cartao>

                    <x-grafico.cartao titulo="Tempo até a autorização"
                        :descricao="'Dias entre a referência e a consulta que achou a autorização. Média de '.Numero::decimal($latencia['media']).' dias — maior: '.Numero::inteiro($latencia['maior']).'.'">
                        <x-grafico.colunas :altura="200" :rotulos="array_column($latencia['faixas'], 'rotulo')"
                            :series="[['nome' => 'Autorizadas', 'cor' => 'grafico-1', 'valores' => array_column($latencia['faixas'], 'total')]]"
                            :rodapes="array_map(fn ($f) => $latencia['total'] > 0 ? Numero::percentual($f['total'] / $latencia['total'] * 100).' das autorizadas' : null, $latencia['faixas'])" />
                        <x-slot:tabela>
                            <x-grafico.tabela :colunas="['Faixa', 'Autorizadas']" :linhas="array_map(fn ($f) => [$f['rotulo'], Numero::inteiro($f['total'])], $latencia['faixas'])" />
                        </x-slot:tabela>
                    </x-grafico.cartao>

                    <x-grafico.cartao titulo="Perfil das chaves" descricao="Tirado da própria chave de acesso: UF, modelo e tipo de emissão." class="xl:col-span-2">
                        <div class="grid gap-4 md:grid-cols-3">
                            @foreach (['ufs' => 'UF', 'modelos' => 'Modelo', 'emissoes' => 'Tipo de emissão'] as $grupo => $titulo)
                                <div class="min-w-0" wire:key="perfil-{{ $grupo }}">
                                    <h4 class="mb-1 px-2 text-xs font-medium text-on-surface-variant">{{ $titulo }}</h4>
                                    <x-grafico.barras larguraRotulo="7rem" :series="[['nome' => 'Contingências', 'cor' => 'grafico-1']]"
                                        :itens="array_map(fn ($i) => ['rotulo' => $i['rotulo'], 'valores' => [$i['total']], 'rodape' => Numero::percentual($i['taxa']).' autorizadas'], $perfil[$grupo])" />
                                </div>
                            @endforeach
                        </div>
                        <x-slot:tabela>
                            <x-grafico.tabela :colunas="['Recorte', 'Contingências', 'Autorizadas', 'Taxa']"
                                :linhas="collect($perfil)->flatMap(fn ($itens, $grupo) => array_map(fn ($i) => [$i['rotulo'], Numero::inteiro($i['total']), Numero::inteiro($i['autorizadas']), Numero::percentual($i['taxa'])], $itens))->all()" />
                        </x-slot:tabela>
                    </x-grafico.cartao>
                </div>
            @endif

            {{-- Pendentes mais antigas: lista, não gráfico --}}
            <section class="rounded-lg bg-surface-container-low p-4" aria-label="Pendentes mais antigas">
                <div class="flex flex-wrap items-center gap-2">
                    <div class="min-w-0">
                        <h3 class="font-medium text-on-surface">Pendentes mais antigas</h3>
                        <p class="text-xs text-on-surface-variant">As 100 não autorizadas presas há mais tempo, de toda a base. Clique na chave para ver o diagnóstico.</p>
                    </div>
                    @if ($clienteId !== null)
                        <button type="button" wire:click="filtrarCliente({{ $clienteId }})"
                            class="ml-auto flex h-8 items-center gap-1 rounded-sm bg-secondary-container px-3 text-xs font-medium text-on-secondary-container hover:brightness-110">
                            {{ $this->nomeCliente($clienteId) }}
                            <x-ui.icone nome="close" class="text-[16px]" />
                            <span class="sr-only">Tirar o filtro do cliente</span>
                        </button>
                    @endif
                </div>

                @if ($pendentes === [])
                    <x-ui.vazio icone="task_alt" titulo="Nenhuma pendente" class="py-8">Tudo o que está na base já foi autorizado.</x-ui.vazio>
                @else
                    <div class="mt-2 max-h-[28rem] overflow-auto">
                        <table class="w-full min-w-[760px] text-sm">
                            <thead class="sticky top-0 bg-surface-container-low">
                                <tr class="text-xs text-on-surface-variant">
                                    <th scope="col" class="border-b border-outline-variant px-2 py-1.5 text-left font-medium">Cliente</th>
                                    <th scope="col" class="border-b border-outline-variant px-2 py-1.5 text-left font-medium">Chave</th>
                                    <th scope="col" class="border-b border-outline-variant px-2 py-1.5 text-left font-medium">Referência</th>
                                    <th scope="col" class="border-b border-outline-variant px-2 py-1.5 text-right font-medium">Dias</th>
                                    <th scope="col" class="border-b border-outline-variant px-2 py-1.5 text-left font-medium">Última consulta</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($pendentes as $p)
                                    <tr class="border-b border-outline-variant/50 last:border-0" wire:key="pendente-{{ $p['chave'] }}">
                                        <td class="max-w-56 truncate px-2 py-1.5 text-on-surface" title="{{ $this->nomeCliente($p['cliente']) }}">{{ $this->nomeCliente($p['cliente']) }}</td>
                                        <td class="px-2 py-1.5 font-mono text-xs">
                                            <a href="{{ route('autorizadas', ['aba' => 'chave', 'chave' => $p['chave']]) }}" wire:navigate class="text-primary hover:underline">{{ $p['chave'] }}</a>
                                        </td>
                                        <td class="px-2 py-1.5 text-on-surface-variant tabular-nums">{{ $p['referencia'] ? \Illuminate\Support\Carbon::parse($p['referencia'])->format('d/m/Y') : '—' }}</td>
                                        <td class="px-2 py-1.5 text-right text-on-surface-variant tabular-nums">{{ Numero::inteiro($p['dias']) }}</td>
                                        <td class="px-2 py-1.5 text-on-surface-variant tabular-nums">{{ $p['ultima_consulta'] ? \Illuminate\Support\Carbon::parse($p['ultima_consulta'])->format('d/m/Y H:i') : 'nunca' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        </div>
    </section>
</div>
