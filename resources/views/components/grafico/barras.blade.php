{{--
    Barras deitadas, uma por linha (bom para nomes compridos: ranking de clientes, perfil das chaves).
    Barra de 12 px, ponta arredondada de 4 px, base reta, 2 px de folga entre os pedaços; o total vai na ponta.
    itens: [['rotulo' => 'MERCADO X', 'valores' => [120, 30], 'rodape' => '80% autorizadas', 'clique' => 'filtrarCliente(12)'], ...]
    series: [['nome' => 'Autorizadas', 'cor' => 'grafico-1'], ...] (na mesma ordem dos valores)
    clique: ação do Livewire ao clicar na linha (opcional). selecionado: índice da linha marcada.
--}}
@props([
    'itens' => [],
    'series' => [],
    'larguraRotulo' => 'min(16rem, 40%)',
    'selecionado' => null,
])

@use('App\Support\Numero')

@php
    $maior = max(1, ...array_map(fn (array $item): float => (float) array_sum($item['valores']), $itens ?: [['valores' => [0]]]));
    $n = count($itens);
@endphp

<ul class="flex flex-col gap-1">
    @foreach ($itens as $i => $item)
        @php
            $total = array_sum($item['valores']);
            $linhas = array_map(fn (array $s, $v): array => ['valor' => Numero::inteiro($v), 'nome' => $s['nome'], 'cor' => $s['cor']], $series, $item['valores']);
            $tag = isset($item['clique']) ? 'button' : 'div';
        @endphp
        <li wire:key="barra-{{ $i }}">
            <{{ $tag }} @if ($tag === 'button') type="button" wire:click="{{ $item['clique'] }}" @if ($selecionado === $i) aria-pressed="true" @endif @else tabindex="0" @endif
                aria-label="{{ $item['rotulo'] }}: {{ implode(', ', array_map(fn ($l) => $l['valor'].' '.mb_strtolower($l['nome']), $linhas)) }}"
                @class([
                    'group relative grid w-full items-center gap-3 rounded-sm px-2 py-1 text-left outline-none transition hover:bg-on-surface/5 focus-visible:bg-on-surface/5',
                    'bg-secondary-container/60' => $selecionado === $i,
                ])
                style="grid-template-columns: minmax(0, {{ $larguraRotulo }}) minmax(0, 1fr)">
                <span class="truncate text-sm text-on-surface" title="{{ $item['rotulo'] }}">{{ $item['rotulo'] }}</span>

                <span class="flex min-w-0 items-center gap-2">
                    <span class="flex h-3 min-w-[2px] gap-[2px] transition group-hover:brightness-125 group-focus-visible:brightness-125" style="width: {{ $total / $maior * 85 }}%">
                        @foreach ($series as $j => $s)
                            @if (($item['valores'][$j] ?? 0) > 0)
                                <span class="h-full min-w-[2px] last:rounded-r-[4px]" style="flex: {{ $item['valores'][$j] }} 1 0; background: var(--color-{{ $s['cor'] }})"></span>
                            @endif
                        @endforeach
                    </span>
                    <span class="shrink-0 text-xs text-on-surface-variant tabular-nums">{{ Numero::inteiro($total) }}</span>
                </span>

                <x-grafico.dica :titulo="$item['rotulo']" :linhas="$linhas" :rodape="$item['rodape'] ?? null" lado="direita" />
            </{{ $tag }}>
        </li>
    @endforeach
</ul>
