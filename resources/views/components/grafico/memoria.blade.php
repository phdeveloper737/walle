{{--
    Mapa do cérebro do RAG (SVG gerado no servidor; zoom, arraste e destaque com Alpine, sem biblioteca).
    nos/conexoes/grupos vêm de App\Rag\IndiceConhecimento::mapa(); a posição vem do layout de força:
    memórias parecidas ficam perto. Ex.: <x-grafico.memoria :nos="$mapa['nos']" :conexoes="$mapa['conexoes']" :grupos="$mapa['grupos']" :selecionada="12" :limiar="0.65" />
--}}
@props([
    'nos',
    'conexoes',
    'grupos' => [],
    'selecionada' => 0,
    'limiar' => 0.65,
    'categorias' => [],
])

@php
    $largura = \App\Rag\MapaConhecimento::LARGURA;
    $altura = \App\Rag\MapaConhecimento::ALTURA;
    $porId = collect($nos)->keyBy('id');
    $haSelecao = $porId->has($selecionada);
    $ligadas = collect($conexoes)
        ->filter(fn (array $c): bool => $c['origem'] === $selecionada || $c['destino'] === $selecionada)
        ->flatMap(fn (array $c): array => [$c['origem'], $c['destino']])
        ->flip();
    $rotulados = collect($nos)->sortByDesc('grau')->take(14)->pluck('id')->flip();
    $raio = fn (array $no): float => min(14.0, 4 + 2 * sqrt($no['grau']));
@endphp

<div {{ $attributes->class('relative overflow-hidden rounded-md bg-surface-container-low') }}
    x-data="{
        escala: 1, x: 0, y: 0, foco: null, texto: '', pressionado: false, moveu: false, inicio: [0, 0, 0, 0],
        proporcao() { const r = this.$refs.svg.getBoundingClientRect(); return [r, Math.max({{ $largura }} / r.width, {{ $altura }} / r.height)] },
        ponto(e) { const [r, s] = this.proporcao(); return [(e.clientX - r.left) * s - (r.width * s - {{ $largura }}) / 2, (e.clientY - r.top) * s - (r.height * s - {{ $altura }}) / 2] },
        zoom(f, px = {{ $largura / 2 }}, py = {{ $altura / 2 }}) { const nova = Math.min(8, Math.max(0.5, this.escala * f)); const k = nova / this.escala; this.x = px - (px - this.x) * k; this.y = py - (py - this.y) * k; this.escala = nova },
        roda(e) { const [px, py] = this.ponto(e); this.zoom(e.deltaY < 0 ? 1.2 : 1 / 1.2, px, py) },
        pegar(e) { this.pressionado = true; this.moveu = false; this.inicio = [e.clientX, e.clientY, this.x, this.y] },
        mover(e) {
            if (! this.pressionado) return;
            const dx = e.clientX - this.inicio[0], dy = e.clientY - this.inicio[1];
            if (! this.moveu && Math.hypot(dx, dy) < 4) return;
            if (! this.moveu) { this.moveu = true; this.$refs.svg.setPointerCapture(e.pointerId) }
            const [, s] = this.proporcao(); this.x = this.inicio[2] + dx * s; this.y = this.inicio[3] + dy * s
        },
        soltar(e) { this.pressionado = false; if (this.$refs.svg.hasPointerCapture(e.pointerId)) this.$refs.svg.releasePointerCapture(e.pointerId) },
        ajustar() { this.escala = 1; this.x = 0; this.y = 0 },
        telaCheia() { document.fullscreenElement ? document.exitFullscreen() : this.$root.requestFullscreen() },
    }">
    <div class="absolute top-2 right-2 z-10 flex items-center gap-0.5 rounded-full bg-surface-container-high/90 p-0.5 shadow-md shadow-black/30">
        <x-ui.botao-icone icone="zoom_in" rotulo="Aproximar" x-on:click="zoom(1.3)" />
        <x-ui.botao-icone icone="zoom_out" rotulo="Afastar" x-on:click="zoom(1 / 1.3)" />
        <x-ui.botao-icone icone="fit_screen" rotulo="Enquadrar o mapa" x-on:click="ajustar()" />
        <x-ui.botao-icone icone="fullscreen" rotulo="Tela cheia" x-on:click="telaCheia()" />
    </div>

    <p class="pointer-events-none absolute bottom-2 left-2 z-10 max-w-[min(42rem,calc(100%-1rem))] truncate rounded-sm bg-surface-container-high/90 px-2 py-1 text-xs text-on-surface-variant"
        x-text="texto || 'Role para aproximar, arraste para mover e clique numa memória para abrir as ramificações dela.'">
        Role para aproximar, arraste para mover e clique numa memória para abrir as ramificações dela.
    </p>

    <svg x-ref="svg" viewBox="0 0 {{ $largura }} {{ $altura }}" class="block h-[36rem] w-full touch-none select-none"
        x-bind:class="moveu && pressionado ? 'cursor-grabbing' : 'cursor-grab'"
        x-on:wheel.prevent="roda($event)" x-on:pointerdown="pegar($event)" x-on:pointermove="mover($event)"
        x-on:pointerup="soltar($event)" x-on:pointercancel="soltar($event)"
        x-on:click.capture="if (moveu) { $event.stopPropagation(); $event.preventDefault(); moveu = false }"
        role="group" aria-label="Mapa de similaridade das memórias. Os mesmos vínculos estão na tabela do gráfico.">
        <g x-bind:transform="`translate(${x} ${y}) scale(${escala})`">
            @foreach ($grupos as $grupo)
                <g wire:key="grupo-{{ $grupo['id'] }}" aria-hidden="true">
                    <circle cx="{{ $grupo['x'] }}" cy="{{ $grupo['y'] }}" r="{{ $grupo['raio'] }}" fill="var(--color-grafico-contexto)" fill-opacity="0.07"
                        stroke="var(--color-grafico-contexto)" stroke-opacity="0.45" stroke-width="1" stroke-dasharray="4 4" />
                    <text x="{{ $grupo['x'] }}" y="{{ max(14, $grupo['y'] - $grupo['raio'] - 6) }}" text-anchor="middle" font-size="12"
                        fill="var(--color-on-surface-variant)" stroke="var(--color-surface-container-low)" stroke-width="3" paint-order="stroke">
                        Grupo {{ $grupo['id'] }} · {{ $categorias[$grupo['categoria']] ?? $grupo['categoria'] }} · {{ $grupo['total'] }}
                    </text>
                </g>
            @endforeach

            @foreach ($conexoes as $conexao)
                @php
                    $a = $porId[$conexao['origem']];
                    $b = $porId[$conexao['destino']];
                    $forca = max(0.0, min(1.0, ($conexao['similaridade'] - $limiar) / max(0.01, 1 - $limiar)));
                    $daSelecao = $haSelecao && ($conexao['origem'] === $selecionada || $conexao['destino'] === $selecionada);
                    $controleX = ($a['x'] + $b['x']) / 2 - ($b['y'] - $a['y']) * 0.12;
                    $controleY = ($a['y'] + $b['y']) / 2 + ($b['x'] - $a['x']) * 0.12;
                @endphp
                <path d="M{{ $a['x'] }} {{ $a['y'] }} Q{{ round($controleX, 1) }} {{ round($controleY, 1) }} {{ $b['x'] }} {{ $b['y'] }}" fill="none"
                    stroke="var(--color-{{ $daSelecao ? 'grafico-2' : 'grafico-contexto' }})"
                    stroke-width="{{ round(($daSelecao ? 1.4 : 0.6) + 2.4 * $forca, 2) }}"
                    stroke-opacity="{{ $haSelecao && ! $daSelecao ? 0.15 : round(0.3 + 0.55 * $forca, 2) }}"
                    class="transition-opacity" aria-hidden="true"
                    x-bind:class="foco !== null && foco !== {{ $conexao['origem'] }} && foco !== {{ $conexao['destino'] }} ? 'opacity-10' : ''" />
            @endforeach

            @foreach ($nos as $no)
                @php
                    $r = $raio($no);
                    $eSelecionada = $no['id'] === $selecionada;
                    $eLigada = $haSelecao && ! $eSelecionada && $ligadas->has($no['id']);
                    $descricao = "#{$no['id']} · {$no['agente']} · ".($categorias[$no['categoria']] ?? $no['categoria'])." · {$no['grau']} ligações · {$no['resumo']}";
                @endphp
                <g wire:key="no-{{ $no['id'] }}" transform="translate({{ $no['x'] }} {{ $no['y'] }})" role="button" tabindex="0"
                    aria-label="Abrir as ramificações da memória #{{ $no['id'] }} de {{ $no['agente'] }}, {{ $no['grau'] }} ligações"
                    aria-pressed="{{ $eSelecionada ? 'true' : 'false' }}" data-descricao="{{ $descricao }}"
                    class="group/no cursor-pointer outline-none" @if ($haSelecao && ! $eSelecionada && ! $eLigada) opacity="0.35" @endif
                    wire:click="selecionar({{ $no['id'] }})"
                    x-on:keydown.enter.prevent="$wire.selecionar({{ $no['id'] }})" x-on:keydown.space.prevent="$wire.selecionar({{ $no['id'] }})"
                    x-on:mouseenter="foco = {{ $no['id'] }}; texto = $el.dataset.descricao" x-on:mouseleave="foco = null; texto = ''"
                    x-on:focus="foco = {{ $no['id'] }}; texto = $el.dataset.descricao" x-on:blur="foco = null; texto = ''">
                    <title>{{ $descricao }}</title>
                    @if ($eSelecionada)
                        <circle r="{{ $r + 9 }}" fill="var(--color-grafico-2)" fill-opacity="0.18" stroke="var(--color-grafico-2)" stroke-width="1.5" />
                    @endif
                    <circle r="{{ $r + 5 }}" fill="none" stroke="var(--color-primary)" stroke-width="2" class="opacity-0 transition-opacity group-hover/no:opacity-100 group-focus-visible/no:opacity-100" />
                    <circle r="{{ $eSelecionada ? $r + 3 : $r }}" fill="var(--color-{{ $eSelecionada ? 'grafico-2' : 'grafico-1' }})"
                        @if ($eLigada) stroke="var(--color-grafico-2)" stroke-width="2.5" @endif />
                    @if ($eSelecionada || $eLigada || $rotulados->has($no['id']))
                        <text y="{{ -($r + 7) }}" text-anchor="middle" font-size="11" fill="var(--color-on-surface)"
                            stroke="var(--color-surface-container-low)" stroke-width="3" paint-order="stroke">#{{ $no['id'] }}</text>
                    @endif
                </g>
            @endforeach
        </g>
    </svg>
</div>
