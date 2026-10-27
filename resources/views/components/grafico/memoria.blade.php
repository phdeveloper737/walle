@props(['nos', 'conexoes', 'selecionada' => 0])
@php($porId = collect($nos)->keyBy('id'))
<svg viewBox="0 0 800 460" {{ $attributes->class('w-full min-w-[800px]') }} role="group" aria-label="Mapa de similaridade das memórias. Os mesmos vínculos estão disponíveis na tabela do gráfico.">
    @foreach ($conexoes as $conexao)
        <line x1="{{ $porId[$conexao['origem']]['x'] }}" y1="{{ $porId[$conexao['origem']]['y'] }}"
            x2="{{ $porId[$conexao['destino']]['x'] }}" y2="{{ $porId[$conexao['destino']]['y'] }}"
            stroke="var(--color-grafico-contexto)" stroke-width="1.5" />
    @endforeach
    @foreach ($nos as $no)
        <g wire:key="no-{{ $no['id'] }}" transform="translate({{ $no['x'] }}, {{ $no['y'] }})">
            <circle r="{{ $no['id'] === $selecionada ? 15 : 10 }}" fill="var(--color-{{ $no['id'] === $selecionada ? 'grafico-2' : 'grafico-1' }})" />
            <text y="-20" text-anchor="middle" fill="var(--color-on-surface)" font-size="12">#{{ $no['id'] }}</text>
            <foreignObject x="-22" y="-22" width="44" height="44">
                <x-ui.botao-icone icone="" :rotulo="'Ler memória #'.$no['id'].' de '.$no['agente']" wire:click="$set('memoria', {{ $no['id'] }})" class="focus-visible:ring-2 focus-visible:ring-primary" />
            </foreignObject>
        </g>
    @endforeach
</svg>
