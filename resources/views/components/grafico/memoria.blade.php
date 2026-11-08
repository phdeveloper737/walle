{{--
    Mapa do cérebro do RAG (estilo Obsidian: física de forças em tempo real, zoom, pan, arraste e destaque sem biblioteca externa).
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
    $rotulados = collect($nos)->sortByDesc('grau')->take(14)->pluck('id')->flip();
    $raio = fn (array $no): float => min(12.0, 3.5 + 2 * sqrt($no['grau']));
    $resumir = function (string $conteudo): string {
        $texto = trim((string) preg_replace('/\s+/u', ' ', strip_tags($conteudo)));
        return mb_strlen($texto) > 28 ? mb_substr($texto, 0, 27).'…' : $texto;
    };

    $dadosJs = [
        'largura' => $largura,
        'altura' => $altura,
        'selecionada' => $selecionada,
        'rotulados' => $rotulados->keys()->values()->all(),
        'nos' => array_map(fn (array $no): array => [
            'id' => (int) $no['id'],
            'x' => (float) $no['x'],
            'y' => (float) $no['y'],
            'grau' => (int) $no['grau'],
            'grupo' => (int) ($no['grupo'] ?? 0),
            'rotulo' => $resumir($no['resumo'] ?? ''),
            'descricao' => "#{$no['id']} · {$no['agente']} · ".($categorias[$no['categoria']] ?? $no['categoria'])." · {$no['grau']} ligações · ".($no['resumo'] ?? ''),
        ], $nos),
        'conexoes' => array_map(fn (array $c): array => [
            'a' => (int) $c['origem'],
            'b' => (int) $c['destino'],
            'forca' => max(0.0, min(1.0, ($c['similaridade'] - $limiar) / max(0.01, 1 - $limiar))),
        ], $conexoes),
    ];
@endphp

<div {{ $attributes->class('relative overflow-hidden rounded-md bg-surface-container-low') }} wire:ignore
    x-data="{
        dados: @js($dadosJs),
        escala: 1, panX: 0, panY: 0, foco: null, texto: '', cheio: false,
        arrastando: null, movido: 0, inicioX: 0, inicioY: 0, deslocamento: { x: 0, y: 0 },
        panInicio: null, alfa: 0.1, animando: false, reduzido: false,
        porId: {}, nos: [], linhas: [], noEl: {}, rotuloEl: {}, pontoEl: {}, haloEl: {}, anelEl: {}, rotulados: new Set(),
        escalaTela: 1,
        iniciar(ligacao) {
            this.livewire = ligacao
            this.reduzido = window.matchMedia('(prefers-reduced-motion: reduce)').matches
            this.svg = this.$refs.svg
            this.grupo = this.$refs.mundo
            this.nos = this.dados.nos.map(n => ({ ...n, x: n.x, y: n.y, xInicial: n.x, yInicial: n.y, vx: 0, vy: 0, r: Math.min(12, 3.5 + 2 * Math.sqrt(n.grau)) }))
            this.porId = {}
            this.nos.forEach(n => { this.porId[n.id] = n })
            this.rotulados = new Set(this.dados.rotulados)
            this.noEl = {}; this.rotuloEl = {}; this.pontoEl = {}; this.haloEl = {}; this.anelEl = {}
            this.nos.forEach(n => {
                const el = this.svg.querySelector('[data-no=&quot;' + n.id + '&quot;]')
                if (!el) return
                this.noEl[n.id] = el
                this.pontoEl[n.id] = el.querySelector('.ponto')
                this.haloEl[n.id] = el.querySelector('.halo')
                this.anelEl[n.id] = el.querySelector('.anel')
                this.rotuloEl[n.id] = el.querySelector('.rotulo')
            })
            this.linhas = Array.prototype.slice.call(this.svg.querySelectorAll('[data-aresta]'))
            this.selecionada = Number(this.dados.selecionada) || 0
            this.acomodar()
            this.desenhar()
            this.enquadrar()
            ligacao.$watch('memoria', (v) => { this.selecionada = Number(v) || 0; this.aplicar() })
        },
        {{-- Roda a simulação inteira antes de desenhar (cerca de 300 passos): a tela já abre com as memórias espalhadas. --}}
        acomodar() {
            this.alfa = 1
            while (this.alfa > 0.001) { this.passo(); this.alfa *= 0.977 }
            this.alfa = 0
        },
        {{-- Ajusta zoom e posição para todas as memórias caberem na área, com margem. --}}
        enquadrar() {
            const nos = Alpine.raw(this.nos)
            if (nos.length === 0) return
            let x0 = Infinity, y0 = Infinity, x1 = -Infinity, y1 = -Infinity
            for (const no of nos) { x0 = Math.min(x0, no.x); y0 = Math.min(y0, no.y); x1 = Math.max(x1, no.x); y1 = Math.max(y1, no.y) }
            const margem = 50, largura = this.dados.largura, altura = this.dados.altura
            const escala = Math.min(2, Math.max(0.1, Math.min(largura / (x1 - x0 + 2 * margem || 1), altura / (y1 - y0 + 2 * margem || 1))))
            this.escala = escala; this.escalaBase = escala
            this.panX = largura / 2 - (x0 + x1) / 2 * escala
            this.panY = altura / 2 - (y0 + y1) / 2 * escala
            this.aplicar()
        },
        ponto(e) {
            const r = this.svg.getBoundingClientRect()
            this.escalaTela = Math.min(r.width / this.dados.largura, r.height / this.dados.altura) || 1
            return {
                x: (e.clientX - r.left - (r.width - this.dados.largura * this.escalaTela) / 2) / this.escalaTela,
                y: (e.clientY - r.top - (r.height - this.dados.altura * this.escalaTela) / 2) / this.escalaTela,
            }
        },
        mundo(e) {
            const p = this.ponto(e)
            return { x: (p.x - this.panX) / this.escala, y: (p.y - this.panY) / this.escala }
        },
        zoom(fator, cx = this.dados.largura / 2, cy = this.dados.altura / 2) {
            const nova = Math.min(8, Math.max(0.1, this.escala * fator))
            const k = nova / this.escala
            this.panX = cx - (cx - this.panX) * k
            this.panY = cy - (cy - this.panY) * k
            this.escala = nova
            this.aplicar()
        },
        roda(e) {
            const p = this.ponto(e)
            this.zoom(e.deltaY < 0 ? 1.15 : 1 / 1.15, p.x, p.y)
        },
        pegar(e) {
            if (e.button !== undefined && e.button !== 0 && e.pointerType === 'mouse') return
            const alvo = e.target.closest('[data-no]')
            this.movido = 0
            this.inicioX = e.clientX; this.inicioY = e.clientY
            if (alvo) {
                const id = Number(alvo.dataset.no)
                const no = this.porId[id]
                const p = this.mundo(e)
                this.arrastando = id
                this.deslocamento = { x: no.x - p.x, y: no.y - p.y }
            } else {
                const r = this.svg.getBoundingClientRect()
                this.escalaTela = Math.min(r.width / this.dados.largura, r.height / this.dados.altura) || 1
                this.panInicio = { x: e.clientX, y: e.clientY, px: this.panX, py: this.panY }
            }
            if (this.svg.setPointerCapture) this.svg.setPointerCapture(e.pointerId)
        },
        mover(e) {
            if (this.arrastando !== null) {
                this.movido = Math.max(this.movido, Math.hypot(e.clientX - this.inicioX, e.clientY - this.inicioY))
                const p = this.mundo(e)
                const no = this.porId[this.arrastando]
                no.x = p.x + this.deslocamento.x
                no.y = p.y + this.deslocamento.y
                no.vx = 0; no.vy = 0
                if (!this.reduzido) this.reaquecer(0.3)
                this.desenhar()
                return
            }
            if (this.panInicio) {
                this.panX = this.panInicio.px + (e.clientX - this.panInicio.x) / this.escalaTela
                this.panY = this.panInicio.py + (e.clientY - this.panInicio.y) / this.escalaTela
            }
        },
        soltar(e) {
            if (this.arrastando !== null) {
                const id = this.arrastando
                const clicou = this.movido < 4
                this.arrastando = null
                if (clicou) this.escolher(id)
            }
            this.panInicio = null
            if (this.svg.hasPointerCapture && this.svg.hasPointerCapture(e.pointerId)) this.svg.releasePointerCapture(e.pointerId)
        },
        ajustar() { this.enquadrar() },
        telaCheia() { document.fullscreenElement ? document.exitFullscreen() : this.$root.requestFullscreen() },
        reorganizar() {
            Alpine.raw(this.nos).forEach(n => { n.x = n.xInicial; n.y = n.yInicial; n.vx = 0; n.vy = 0 })
            this.acomodar(); this.desenhar(); this.enquadrar()
        },
        escolher(id) { this.livewire.selecionar(id) },
        sobre(e) {
            const el = e.target.closest('[data-no]')
            const id = el ? Number(el.dataset.no) : null
            if (id !== this.foco) {
                this.foco = id
                this.texto = el ? (el.dataset.descricao || '') : ''
                this.aplicar()
            }
        },
        sair() {
            if (this.foco !== null) { this.foco = null; this.texto = ''; this.aplicar() }
        },
        reaquecer(v) { this.alfa = Math.max(this.alfa, v); this.agendar() },
        agendar() {
            if (!this.animando && !this.reduzido) {
                this.animando = true
                requestAnimationFrame(() => this.animar())
            }
        },
        animar() {
            if (this.alfa < 0.005 || this.reduzido) { this.animando = false; return }
            this.passo()
            this.alfa *= 0.977
            this.desenhar()
            requestAnimationFrame(() => this.animar())
        },
        passo() {
            {{-- Objetos crus: pelo proxy reativo do Alpine, os ~45 mil pares por quadro ficam lentos. --}}
            const nos = Alpine.raw(this.nos), porId = Alpine.raw(this.porId), conexoes = Alpine.raw(this.dados).conexoes, arrastando = this.arrastando
            {{--
                Forças no estilo do d3/Obsidian: repulsão que cai com 1/d (até REPULSAO_ALCANCE), mola em cada
                ligação (mais curta quanto mais parecida, força dividida pelo grau para os hubs não esmagarem
                o grupo), gravidade fraca para o centro e colisão que impede pontos encostados. Sem limite de
                área: a vista é que se ajusta aos pontos (enquadrar).
            --}}
            const REPULSAO = 200, REPULSAO_ALCANCE = 500, MOLA_MIN = 35, MOLA_MAX = 90, ENTRE_GRUPOS = 3, GRAVIDADE_X = 0.018, GRAVIDADE_Y = 0.045, GRUPO = 0.2, FOLGA = 14
            const n = nos.length, alfa = this.alfa
            const cx = this.dados.largura / 2, cy = this.dados.altura / 2
            const alcance2 = REPULSAO_ALCANCE * REPULSAO_ALCANCE
            for (let i = 0; i < n; i++) {
                const a = nos[i]
                for (let j = i + 1; j < n; j++) {
                    const b = nos[j]
                    let dx = b.x - a.x, dy = b.y - a.y
                    let d2 = dx * dx + dy * dy
                    if (d2 < 0.01) { dx = (Math.random() - 0.5) || 0.1; dy = (Math.random() - 0.5) || 0.1; d2 = dx * dx + dy * dy }
                    if (d2 < alcance2) {
                        const w = REPULSAO * alfa / d2
                        a.vx -= dx * w; a.vy -= dy * w; b.vx += dx * w; b.vy += dy * w
                    }
                    const minimo = a.r + b.r + FOLGA
                    if (d2 < minimo * minimo) {
                        const d = Math.sqrt(d2), s = (minimo - d) / d * 0.25
                        a.x -= dx * s; a.y -= dy * s; b.x += dx * s; b.y += dy * s
                    }
                }
            }
            for (const aresta of conexoes) {
                const a = porId[aresta.a], b = porId[aresta.b]
                if (!a || !b) continue
                const dx = b.x + b.vx - a.x - a.vx, dy = b.y + b.vy - a.y - a.vy
                const d = Math.max(0.1, Math.sqrt(dx * dx + dy * dy))
                const mesmoGrupo = a.grupo && a.grupo === b.grupo
                const alvo = (MOLA_MIN + (1 - aresta.forca) * (MOLA_MAX - MOLA_MIN)) * (mesmoGrupo ? 1 : ENTRE_GRUPOS)
                const l = (d - alvo) / d * alfa * (mesmoGrupo ? 0.8 : 0.25) / Math.max(1, Math.min(a.grau, b.grau))
                a.vx += dx * l; a.vy += dy * l; b.vx -= dx * l; b.vy -= dy * l
            }
            {{-- Puxão leve para o centro do grupo de assunto (grupo 0 = sem grupo): os aglomerados ficam visíveis. --}}
            const centros = {}
            for (const no of nos) {
                if (!no.grupo) continue
                const c = centros[no.grupo] ??= { x: 0, y: 0, n: 0 }
                c.x += no.x; c.y += no.y; c.n++
            }
            for (const no of nos) {
                const c = no.grupo ? centros[no.grupo] : null
                if (!c || c.n < 2) continue
                no.vx += (c.x / c.n - no.x) * GRUPO * alfa
                no.vy += (c.y / c.n - no.y) * GRUPO * alfa
            }
            for (const no of nos) {
                if (no.id === arrastando) { no.vx = 0; no.vy = 0; continue }
                no.vx = (no.vx + (cx - no.x) * GRAVIDADE_X * alfa) * 0.6
                no.vy = (no.vy + (cy - no.y) * GRAVIDADE_Y * alfa) * 0.6
                no.x += no.vx
                no.y += no.vy
            }
        },
        desenhar() {
            const nos = Alpine.raw(this.nos), porId = Alpine.raw(this.porId), noEl = Alpine.raw(this.noEl)
            for (const no of nos) {
                const el = noEl[no.id]
                if (el) el.setAttribute('transform', 'translate(' + no.x.toFixed(1) + ' ' + no.y.toFixed(1) + ')')
            }
            for (const linha of Alpine.raw(this.linhas)) {
                const a = porId[Number(linha.dataset.a)]
                const b = porId[Number(linha.dataset.b)]
                if (!a || !b) continue
                linha.setAttribute('x1', a.x.toFixed(1))
                linha.setAttribute('y1', a.y.toFixed(1))
                linha.setAttribute('x2', b.x.toFixed(1))
                linha.setAttribute('y2', b.y.toFixed(1))
            }
        },
        aplicar() {
            const foco = this.foco ?? (this.selecionada > 0 ? this.selecionada : null)
            const vizinhos = new Set()
            if (foco !== null) {
                for (const c of this.dados.conexoes) {
                    if (c.a === foco) vizinhos.add(c.b)
                    if (c.b === foco) vizinhos.add(c.a)
                }
            }
            const relativa = this.escala / (this.escalaBase || 1)
            const rampa = Math.min(1, Math.max(0, (relativa - 1.6) / 0.6))
            const fonte = Math.min(40, 13 / this.escala)
            for (const no of this.nos) {
                const el = this.noEl[no.id], ponto = this.pontoEl[no.id], halo = this.haloEl[no.id], anel = this.anelEl[no.id], rotulo = this.rotuloEl[no.id]
                if (!el) continue
                const emFoco = this.foco === no.id
                const ligado = this.foco !== null && (emFoco || vizinhos.has(no.id))
                const cor = emFoco ? 'grafico-2' : 'grafico-1'
                ponto.setAttribute('fill', 'var(--color-' + cor + ')')
                halo.setAttribute('fill', 'var(--color-' + cor + ')')
                el.style.opacity = this.foco !== null && !ligado ? '0.12' : '1'
                el.setAttribute('aria-pressed', no.id === this.selecionada ? 'true' : 'false')
                if (anel) anel.style.opacity = no.id === this.selecionada ? '1' : '0'

                if (rotulo) {
                    const aceso = no.id === foco || vizinhos.has(no.id)
                    let op = this.rotulados.has(no.id) ? 1 : rampa
                    if (aceso) op = 1
                    rotulo.style.opacity = String(op)
                    rotulo.setAttribute('font-size', fonte.toFixed(1))
                    rotulo.setAttribute('y', (no.r + 2 + fonte).toFixed(1))
                }
            }
            for (const linha of this.linhas) {
                const a = Number(linha.dataset.a), b = Number(linha.dataset.b)
                const forca = Number(linha.dataset.forca) || 0
                const tocaFoco = this.foco !== null && (a === this.foco || b === this.foco)
                const tocaSelecao = this.selecionada > 0 && (a === this.selecionada || b === this.selecionada)
                let cor = 'grafico-contexto', opacidade = 0.18 + 0.27 * forca, espessura = 0.6 + 0.9 * forca
                if (tocaFoco) { cor = 'grafico-2'; opacidade = 0.9; espessura = 1.4 }
                else if (this.foco !== null) { opacidade = 0.12 }
                else if (tocaSelecao) { cor = 'grafico-2'; opacidade = 0.8 }
                linha.setAttribute('stroke', 'var(--color-' + cor + ')')
                linha.setAttribute('stroke-width', espessura.toFixed(2))
                {{-- A opacidade do servidor vale só sem JS; daqui em diante quem manda é o style. --}}
                linha.removeAttribute('stroke-opacity')
                linha.style.opacity = String(opacidade)
            }
        }
    }"
    x-init="iniciar($wire)"
    x-on:fullscreenchange.document="cheio = !!document.fullscreenElement"
    x-bind:class="cheio ? 'flex flex-col' : ''">

    <div class="absolute top-2 right-2 z-10 flex items-center gap-0.5 rounded-full bg-surface-container-high/90 p-0.5 shadow-md shadow-black/30">
        <x-ui.botao-icone icone="zoom_in" rotulo="Aproximar" x-on:click="zoom(1.3)" />
        <x-ui.botao-icone icone="zoom_out" rotulo="Afastar" x-on:click="zoom(1 / 1.3)" />
        <x-ui.botao-icone icone="fit_screen" rotulo="Enquadrar o mapa" x-on:click="ajustar()" />
        <x-ui.botao-icone icone="restart_alt" rotulo="Reorganizar" x-on:click="reorganizar()" />
        <x-ui.botao-icone icone="fullscreen" rotulo="Tela cheia" x-on:click="telaCheia()" />
    </div>

    <p class="pointer-events-none absolute bottom-2 left-2 z-10 max-w-[min(42rem,calc(100%-1rem))] truncate rounded-sm bg-surface-container-high/90 px-2 py-1 text-xs text-on-surface-variant"
        x-text="texto || 'Arraste para mover o mapa, role para aproximar e clique numa memória para abrir as ramificações dela.'">
        Arraste para mover o mapa, role para aproximar e clique numa memória para abrir as ramificações dela.
    </p>

    <svg x-ref="svg" viewBox="0 0 {{ $largura }} {{ $altura }}"
        class="block w-full touch-none select-none"
        x-bind:class="(cheio ? 'min-h-0 flex-1' : 'h-[42rem]') + ' ' + (arrastando !== null ? 'cursor-grabbing' : 'cursor-grab')"
        x-on:wheel.prevent="roda($event)"
        x-on:pointerdown="pegar($event)"
        x-on:pointermove="mover($event)"
        x-on:pointerup="soltar($event)"
        x-on:pointercancel="soltar($event)"
        x-on:pointerover="sobre($event)"
        x-on:pointerleave="sair()"
        x-on:focusin="sobre($event)"
        x-on:focusout="sair()"
        role="group"
        aria-label="Mapa de similaridade das memórias. Os mesmos vínculos estão na tabela do gráfico.">
        <g x-ref="mundo" x-bind:transform="`translate(${panX} ${panY}) scale(${escala})`">
            @foreach ($conexoes as $conexao)
                @php
                    $a = $porId[$conexao['origem']];
                    $b = $porId[$conexao['destino']];
                    $forca = max(0.0, min(1.0, ($conexao['similaridade'] - $limiar) / max(0.01, 1 - $limiar)));
                    $daSelecao = $haSelecao && ($conexao['origem'] === $selecionada || $conexao['destino'] === $selecionada);
                @endphp
                <line data-aresta data-a="{{ $conexao['origem'] }}" data-b="{{ $conexao['destino'] }}" data-forca="{{ round($forca, 3) }}"
                    x1="{{ $a['x'] }}" y1="{{ $a['y'] }}" x2="{{ $b['x'] }}" y2="{{ $b['y'] }}"
                    stroke="var(--color-{{ $daSelecao ? 'grafico-2' : 'grafico-contexto' }})"
                    stroke-width="{{ round(($daSelecao ? 1.4 : 0.6) + 1.4 * $forca, 2) }}"
                    stroke-opacity="{{ $haSelecao && ! $daSelecao ? 0.2 : round(0.18 + 0.27 * $forca, 2) }}"
                    vector-effect="non-scaling-stroke" class="transition-opacity motion-reduce:transition-none" aria-hidden="true" />
            @endforeach

            @foreach ($nos as $no)
                @php
                    $r = $raio($no);
                    $eSelecionada = $no['id'] === $selecionada;
                    $rotulado = $rotulados->has($no['id']);
                    $descricao = "#{$no['id']} · {$no['agente']} · ".($categorias[$no['categoria']] ?? $no['categoria'])." · {$no['grau']} ligações · ".($no['resumo'] ?? '');
                @endphp
                <g wire:key="no-{{ $no['id'] }}" data-no="{{ $no['id'] }}" transform="translate({{ $no['x'] }} {{ $no['y'] }})"
                    role="button" tabindex="0"
                    aria-label="Abrir as ramificações da memória #{{ $no['id'] }} de {{ $no['agente'] }}, {{ $no['grau'] }} ligações"
                    aria-pressed="{{ $eSelecionada ? 'true' : 'false' }}"
                    data-descricao="{{ $descricao }}"
                    class="group/no cursor-pointer outline-none transition-opacity motion-reduce:transition-none"
                    x-on:keydown.enter.prevent="escolher({{ $no['id'] }})"
                    x-on:keydown.space.prevent="escolher({{ $no['id'] }})">
                    <title>{{ $descricao }}</title>
                    <circle class="halo" r="{{ round($r * 2.2, 1) }}" fill="var(--color-grafico-1)" fill-opacity="0.15" />
                    <circle class="ponto" r="{{ $r }}" fill="var(--color-grafico-1)" />
                    <circle class="anel" r="{{ $r + 3 }}" fill="none" stroke="var(--color-grafico-2)" stroke-width="1.5" style="opacity: {{ $eSelecionada ? 1 : 0 }}" />
                    <circle r="{{ $r + 5.5 }}" fill="none" stroke="var(--color-grafico-2)" stroke-width="1.5"
                        class="opacity-0 transition-opacity group-focus-visible/no:opacity-100 motion-reduce:transition-none" />
                    <text class="rotulo transition-opacity motion-reduce:transition-none" y="{{ round($r + 11, 1) }}" text-anchor="middle" font-size="10"
                        fill="var(--color-on-surface-variant)" stroke="var(--color-surface-container-low)" stroke-width="3" paint-order="stroke"
                        style="opacity: {{ $rotulado ? 1 : 0 }}" aria-hidden="true">
                        {{ $resumir($no['resumo'] ?? '') }}
                    </text>
                </g>
            @endforeach
        </g>
    </svg>
</div>
