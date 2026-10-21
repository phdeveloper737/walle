{{--
    Linhas (2 px) com a mira vertical: passar o mouse (ou o teclado) em qualquer ponto do eixo X mostra todas as séries naquele X.
    A linha é SVG esticado (o traço não engrossa: non-scaling-stroke); pontos, textos e dicas são HTML, então não deformam.
    series: [['nome' => 'Outubro', 'cor' => 'grafico-1', 'valores' => [3, null, ...], 'destaque' => true], ...]
    null = sem valor (a linha para ali). destaque: área clarinha embaixo e o valor escrito na ponta.
    formato: inteiro | percentual. topo/marcas: escala fixa (ex.: 0 a 100%).
--}}
@props([
    'rotulos' => [],
    'series' => [],
    'altura' => 180,
    'formato' => 'inteiro',
    'topo' => null,
    'marcas' => null,
    'rotuloACada' => null,
])

@use('App\Support\Numero')

@php
    $n = count($rotulos);
    // Padrão: no máximo 8 rótulos no eixo X
    $rotuloACada ??= max(1, (int) ceil($n / 8));
    $valores = array_filter(array_merge(...array_map(fn (array $s): array => $s['valores'], $series ?: [['valores' => []]])), fn ($v) => $v !== null);
    $escala = $topo !== null ? ['topo' => (float) $topo, 'marcas' => $marcas ?? [0, $topo]] : Numero::escala($valores === [] ? 0 : max($valores));
    $topo = $escala['topo'];
    $formatar = fn ($v): string => $formato === 'percentual' ? Numero::percentual((float) $v) : Numero::inteiro($v);
    $marcaEixo = fn ($v): string => $formato === 'percentual' ? Numero::inteiro($v).'%' : Numero::compacto($v);
    // Mesma posição X das colunas: o meio de cada fatia
    $x = fn (int $i): float => $n > 0 ? ($i + 0.5) / $n * 100 : 50;
    $y = fn ($v): float => 100 - min((float) $v, $topo) / $topo * 100;
@endphp

<div class="flex gap-2">
    <div class="relative w-9 shrink-0 text-right text-[11px] text-on-surface-variant tabular-nums" style="height: {{ $altura }}px" aria-hidden="true">
        @foreach ($escala['marcas'] as $marca)
            <span class="absolute right-0 -translate-y-1/2 leading-none" style="top: {{ $y($marca) }}%">{{ $marcaEixo($marca) }}</span>
        @endforeach
    </div>

    <div class="min-w-0 flex-1">
        <div class="relative" style="height: {{ $altura }}px">
            @foreach ($escala['marcas'] as $marca)
                <div @class(['absolute inset-x-0 h-px', 'bg-grafico-base' => $marca == 0, 'bg-grafico-grade' => $marca != 0])
                    style="top: {{ $y($marca) }}%" aria-hidden="true"></div>
            @endforeach

            <svg class="absolute inset-0 size-full overflow-visible" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">
                {{-- As de contexto primeiro: a série em destaque passa por cima --}}
                @foreach (collect($series)->sortBy(fn ($s) => (int) ($s['destaque'] ?? false)) as $s)
                    @php
                        $pontos = [];
                        foreach ($s['valores'] as $i => $v) {
                            if ($v !== null) {
                                $pontos[] = round($x($i), 3).','.round($y($v), 3);
                            }
                        }
                    @endphp
                    @if (count($pontos) > 1 && ($s['destaque'] ?? false))
                        @php
                            $primeiro = array_key_first(array_filter($s['valores'], fn ($v) => $v !== null));
                            $ultimo = array_key_last(array_filter($s['valores'], fn ($v) => $v !== null));
                        @endphp
                        <polygon points="{{ round($x($primeiro), 3) }},100 {{ implode(' ', $pontos) }} {{ round($x($ultimo), 3) }},100"
                            style="fill: var(--color-{{ $s['cor'] }}); fill-opacity: 0.1" />
                    @endif
                    @if (count($pontos) > 1)
                        <polyline points="{{ implode(' ', $pontos) }}" fill="none" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"
                            vector-effect="non-scaling-stroke" style="stroke: var(--color-{{ $s['cor'] }})" />
                    @endif
                @endforeach
            </svg>

            {{-- Ponto e valor na ponta da série em destaque --}}
            @foreach ($series as $s)
                @php $ultimo = array_key_last(array_filter($s['valores'], fn ($v) => $v !== null)); @endphp
                @if (($s['destaque'] ?? false) && $ultimo !== null)
                    <span class="pointer-events-none absolute size-2.5 -translate-1/2 rounded-full ring-2 ring-surface-container-low"
                        style="left: {{ $x($ultimo) }}%; top: {{ $y($s['valores'][$ultimo]) }}%; background: var(--color-{{ $s['cor'] }})" aria-hidden="true"></span>
                    <span class="pointer-events-none absolute -translate-y-[calc(100%+8px)] text-xs font-medium whitespace-nowrap text-on-surface {{ $x($ultimo) > 70 ? '-translate-x-full' : '-translate-x-1/2' }}"
                        style="left: {{ $x($ultimo) }}%; top: {{ $y($s['valores'][$ultimo]) }}%" aria-hidden="true">{{ $formatar($s['valores'][$ultimo]) }}</span>
                @endif
            @endforeach

            {{-- Mira: uma fatia por X, maior que a linha; mostra a linha vertical, os pontos e a dica --}}
            <div class="absolute inset-0 flex">
                @foreach ($rotulos as $i => $rotulo)
                    @php
                        $linhas = [];
                        foreach ($series as $s) {
                            if (($s['valores'][$i] ?? null) !== null) {
                                $linhas[] = ['valor' => $formatar($s['valores'][$i]), 'nome' => $s['nome'], 'cor' => $s['cor']];
                            }
                        }
                    @endphp
                    <div class="group relative h-full min-w-0 flex-1 outline-none" tabindex="0" wire:key="mira-{{ $i }}"
                        aria-label="{{ $rotulo }}: {{ $linhas === [] ? 'sem dados' : implode(', ', array_map(fn ($l) => $l['nome'].' '.$l['valor'], $linhas)) }}">
                        @if ($linhas !== [])
                            <div class="absolute inset-y-0 left-1/2 hidden w-px bg-on-surface-variant/50 group-hover:block group-focus-visible:block" aria-hidden="true"></div>
                            @foreach ($series as $s)
                                @if (($s['valores'][$i] ?? null) !== null)
                                    <span class="absolute left-1/2 hidden size-2.5 -translate-1/2 rounded-full ring-2 ring-surface-container-low group-hover:block group-focus-visible:block"
                                        style="top: {{ $y($s['valores'][$i]) }}%; background: var(--color-{{ $s['cor'] }})" aria-hidden="true"></span>
                                @endif
                            @endforeach
                            <div class="absolute inset-x-0 top-0">
                                <x-grafico.dica :titulo="$rotulo" :linhas="$linhas" :lado="$i < $n / 4 ? 'esquerda' : ($i >= $n * 3 / 4 ? 'direita' : 'meio')" />
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>

        <div class="mt-1.5 flex text-[11px] text-on-surface-variant" aria-hidden="true">
            @foreach ($rotulos as $i => $rotulo)
                <span class="min-w-0 flex-1 text-center whitespace-nowrap">{{ $i % $rotuloACada === 0 ? $rotulo : '' }}</span>
            @endforeach
        </div>
    </div>
</div>
