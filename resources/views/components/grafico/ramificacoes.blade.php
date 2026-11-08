{{--
    Árvore radial das ramificações de uma memória (SVG gerado no servidor): ela no centro, as ligações diretas
    no primeiro anel e as ligações de cada uma no segundo. Clicar num ramo centraliza a árvore nele.
    arvore vem de App\Rag\MapaConhecimento::ramificacoes(); detalhes: [id => ['agente' => ..., 'resumo' => ...]].
--}}
@props([
    'arvore',
    'detalhes' => [],
    'limiar' => 0.65,
])

@php
    $porId = collect($arvore)->keyBy('id');
    $centro = $arvore[0];
    $curto = fn (string $agente): string => \Illuminate\Support\Str::after($agente, 'walle-');
@endphp

<svg viewBox="0 0 900 620" {{ $attributes->class('block h-auto w-full min-w-[640px]') }} role="group"
    aria-label="Ramificações da memória #{{ $centro['id'] }}. Os mesmos vínculos estão na tabela do gráfico.">
    <circle cx="{{ $centro['x'] }}" cy="{{ $centro['y'] }}" r="155" fill="none" stroke="var(--color-grafico-grade)" stroke-dasharray="2 6" aria-hidden="true" />
    <circle cx="{{ $centro['x'] }}" cy="{{ $centro['y'] }}" r="275" fill="none" stroke="var(--color-grafico-grade)" stroke-dasharray="2 6" aria-hidden="true" />

    @foreach ($arvore as $no)
        @continue($no['pai'] === null)
        @php
            $pai = $porId[$no['pai']];
            $forca = max(0.0, min(1.0, ((float) $no['similaridade'] - $limiar) / max(0.01, 1 - $limiar)));
            $controleX = ($pai['x'] + $no['x']) / 2 - ($no['y'] - $pai['y']) * 0.15;
            $controleY = ($pai['y'] + $no['y']) / 2 + ($no['x'] - $pai['x']) * 0.15;
            $cor = $no['nivel'] === 1 ? 'grafico-2' : 'grafico-contexto';
        @endphp
        <g wire:key="ramo-{{ $no['pai'] }}-{{ $no['id'] }}" aria-hidden="true">
            <path d="M{{ $pai['x'] }} {{ $pai['y'] }} Q{{ round($controleX, 1) }} {{ round($controleY, 1) }} {{ $no['x'] }} {{ $no['y'] }}" fill="none"
                stroke="var(--color-{{ $cor }})" stroke-width="{{ round(($no['nivel'] === 1 ? 1.5 : 1) + 3 * $forca, 2) }}" stroke-opacity="{{ round(0.45 + 0.5 * $forca, 2) }}" stroke-linecap="round" />
            @if ($no['nivel'] === 1)
                <text x="{{ round(($pai['x'] + 2 * $controleX + $no['x']) / 4, 1) }}" y="{{ round(($pai['y'] + 2 * $controleY + $no['y']) / 4, 1) }}"
                    text-anchor="middle" dominant-baseline="middle" font-size="11" fill="var(--color-on-surface-variant)"
                    stroke="var(--color-surface-container-low)" stroke-width="3" paint-order="stroke">{{ number_format((float) $no['similaridade'] * 100, 1, ',', '.') }}%</text>
            @endif
        </g>
    @endforeach

    @foreach ($arvore as $no)
        @php
            $r = match ($no['nivel']) { 0 => 22, 1 => 12, default => 7 };
            $cor = match ($no['nivel']) { 0 => 'grafico-2', 1 => 'grafico-1', default => 'grafico-contexto' };
            $agente = $detalhes[$no['id']]['agente'] ?? '';
            $resumo = $detalhes[$no['id']]['resumo'] ?? '';
            $abaixo = $no['nivel'] > 0 && $no['y'] > $centro['y'] + 1;
        @endphp
        <g wire:key="ramo-no-{{ $no['id'] }}" transform="translate({{ $no['x'] }} {{ $no['y'] }})"
            @if ($no['nivel'] > 0) role="button" tabindex="0" class="group/ramo cursor-pointer outline-none"
                aria-label="Centralizar as ramificações na memória #{{ $no['id'] }} de {{ $agente }}"
                wire:click="selecionar({{ $no['id'] }})"
                x-on:keydown.enter.prevent="$wire.selecionar({{ $no['id'] }})" x-on:keydown.space.prevent="$wire.selecionar({{ $no['id'] }})" @endif>
            <title>#{{ $no['id'] }} · {{ $agente }} · {{ $resumo }}</title>
            @if ($no['nivel'] === 0)
                <circle r="{{ $r + 8 }}" fill="var(--color-grafico-2)" fill-opacity="0.18" />
            @else
                <circle r="{{ $r + 5 }}" fill="none" stroke="var(--color-primary)" stroke-width="2" class="opacity-0 transition-opacity group-hover/ramo:opacity-100 group-focus-visible/ramo:opacity-100" />
            @endif
            <circle r="{{ $r }}" fill="var(--color-{{ $cor }})" />
            <text y="{{ $no['nivel'] === 0 ? -($r + 12) : ($abaixo ? $r + 14 : -($r + 6)) }}" text-anchor="middle" font-size="{{ $no['nivel'] === 0 ? 14 : 11 }}"
                font-weight="{{ $no['nivel'] === 0 ? 500 : 400 }}" fill="var(--color-on-surface)" stroke="var(--color-surface-container-low)" stroke-width="3" paint-order="stroke">
                #{{ $no['id'] }}@if ($no['nivel'] < 2 && $agente !== '') · {{ $curto($agente) }}@endif
            </text>
        </g>
    @endforeach
</svg>
