{{--
    Colunas (empilhadas quando há mais de uma série), em HTML: o texto não estica com a largura.
    Marcas finas (até 24 px), ponta arredondada de 4 px, base reta, 2 px de folga entre os pedaços.
    rotulos: ['01/09', ...]; series: [['nome' => 'Autorizadas', 'cor' => 'grafico-1', 'valores' => [10, 20, ...]], ...]
    rodapes: texto extra da dica por coluna (ex.: a taxa). rotuloACada: mostra 1 rótulo do eixo X a cada N colunas (padrão: no máximo 8 rótulos).
--}}
@props([
    'rotulos' => [],
    'series' => [],
    'rodapes' => [],
    'altura' => 180,
    'rotuloACada' => null,
])

@use('App\Support\Numero')

@php
    $n = count($rotulos);
    $rotuloACada ??= max(1, (int) ceil($n / 8));
    $totais = array_map(fn (int $i): float => (float) array_sum(array_map(fn (array $s) => $s['valores'][$i] ?? 0, $series)), $n > 0 ? range(0, $n - 1) : []);
    $escala = Numero::escala($totais === [] ? 0 : max($totais));
    $topo = $escala['topo'];
@endphp

<div class="flex gap-2">
    {{-- Eixo Y --}}
    <div class="relative w-9 shrink-0 text-right text-[11px] text-on-surface-variant tabular-nums" style="height: {{ $altura }}px" aria-hidden="true">
        @foreach ($escala['marcas'] as $marca)
            <span class="absolute right-0 -translate-y-1/2 leading-none" style="top: {{ 100 - $marca / $topo * 100 }}%">{{ Numero::compacto($marca) }}</span>
        @endforeach
    </div>

    <div class="min-w-0 flex-1">
        <div class="relative" style="height: {{ $altura }}px">
            @foreach ($escala['marcas'] as $marca)
                <div @class(['absolute inset-x-0 h-px', 'bg-grafico-base' => $marca == 0, 'bg-grafico-grade' => $marca != 0])
                    style="top: {{ 100 - $marca / $topo * 100 }}%" aria-hidden="true"></div>
            @endforeach

            <div class="absolute inset-0 flex items-end">
                @foreach ($rotulos as $i => $rotulo)
                    @php
                        $linhas = array_map(fn (array $s): array => ['valor' => Numero::inteiro($s['valores'][$i] ?? 0), 'nome' => $s['nome'], 'cor' => $s['cor']], $series);
                        $descricao = $rotulo.': '.implode(', ', array_map(fn (array $l): string => $l['valor'].' '.mb_strtolower($l['nome']), $linhas));
                    @endphp
                    {{-- A coluna inteira é a área do mouse/teclado, maior que a barra --}}
                    <div class="group relative flex h-full min-w-0 flex-1 items-end justify-center px-px outline-none" tabindex="0" aria-label="{{ $descricao }}" wire:key="coluna-{{ $i }}">
                        @if ($totais[$i] > 0)
                            <div class="flex w-full max-w-6 flex-col-reverse gap-[2px] transition group-hover:brightness-125 group-focus-visible:brightness-125"
                                style="height: {{ $totais[$i] / $topo * 100 }}%">
                                @foreach ($series as $s)
                                    @if (($s['valores'][$i] ?? 0) > 0)
                                        <div class="min-h-[2px] w-full last:rounded-t-[4px]" style="flex: {{ $s['valores'][$i] }} 1 0; background: var(--color-{{ $s['cor'] }})"></div>
                                    @endif
                                @endforeach
                            </div>
                        @endif

                        <x-grafico.dica :titulo="$rotulo" :linhas="$linhas" :rodape="$rodapes[$i] ?? null"
                            :lado="$i < $n / 4 ? 'esquerda' : ($i >= $n * 3 / 4 ? 'direita' : 'meio')" />
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Eixo X --}}
        <div class="mt-1.5 flex text-[11px] text-on-surface-variant" aria-hidden="true">
            @foreach ($rotulos as $i => $rotulo)
                <span class="min-w-0 flex-1 overflow-visible text-center whitespace-nowrap">
                    {{ $i % $rotuloACada === 0 ? $rotulo : '' }}
                </span>
            @endforeach
        </div>
    </div>
</div>
