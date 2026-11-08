<div>
    <div class="flex flex-col gap-4">
        <p class="text-sm text-on-surface-variant">Explore os aprendizados dos agentes. As ligações indicam semelhança de conteúdo, não relações de causa nem regras do projeto.</p>
        @if ($dados['erro'] ?? false)
            <x-ui.aviso tipo="erro">O índice do RAG não está disponível. Confira o contêiner walle-rag e tente atualizar a página.</x-ui.aviso>
        @else
            @php
                $mapa = $this->mapa;
                $selecionada = $this->selecionada;
                $arvore = $this->ramificacoes;
                $limiarFracao = $limiar / 100;
                $nosDoMapa = collect($mapa['nos'] ?? [])->keyBy('id');
                $vizinhosDaSelecionada = $mapa['vizinhos'][$memoria] ?? [];
                $porcentagem = fn (float $valor): string => number_format($valor * 100, 1, ',', '.').'%';
            @endphp

            <div class="grid gap-3 sm:grid-cols-3 xl:grid-cols-5">
                <x-ui.kpi rotulo="Documentos indexados" :valor="$dados['documentos']" icone="description" />
                <x-ui.kpi rotulo="Trechos de memória" :valor="$dados['total']" icone="neurology" />
                <x-ui.kpi rotulo="Agentes com memórias" :valor="count($dados['agentes'])" icone="smart_toy" />
                <x-ui.kpi rotulo="Ligações no mapa" :valor="count($mapa['conexoes'] ?? [])" icone="hub" :detalhe="'similaridade de pelo menos '.$limiar.'%'" />
                <x-ui.kpi rotulo="Grupos de assunto" :valor="count($mapa['grupos'] ?? [])" icone="bubble_chart" :detalhe="'com '.\App\Rag\MapaConhecimento::MINIMO_DO_GRUPO.' memórias ou mais'" />
            </div>
            <p class="text-xs text-on-surface-variant">Última indexação: {{ $dados['ultima'] ?? 'Nenhuma ainda' }} · Horário de Brasília. Os números descrevem o índice salvo; não confirmam a saúde do modelo.</p>

            <x-ui.card titulo="Filtrar memórias" icone="filter_alt">
                <div class="flex flex-col gap-4">
                    <div class="grid gap-3 lg:grid-cols-[minmax(0,1fr)_20rem] lg:items-center">
                        <x-ui.busca wire:model.live.debounce.400ms="busca" placeholder="Buscar no conteúdo das memórias" maxlength="200" />
                        <div class="flex items-center gap-2">
                            <span class="shrink-0 text-xs text-on-surface-variant">Similaridade mínima</span>
                            <x-ui.segmentado wire:model.live="limiar" compacto :opcoes="collect($limiares)->mapWithKeys(fn (int $valor): array => [$valor => $valor.'%'])->all()" />
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-2" aria-label="Categorias">
                        <x-ui.chip :ativo="$categoria === ''" wire:click="$set('categoria', '')">Todas as categorias</x-ui.chip>
                        @foreach ($categorias as $chave => $rotulo)
                            <x-ui.chip wire:key="categoria-{{ $chave }}" :ativo="$categoria === $chave" wire:click="$set('categoria', '{{ $chave }}')">{{ $rotulo }}</x-ui.chip>
                        @endforeach
                    </div>
                    <div class="flex flex-wrap gap-2" aria-label="Agentes">
                        <x-ui.chip :ativo="$agente === ''" wire:click="$set('agente', '')">Todos os agentes</x-ui.chip>
                        @foreach ($dados['agentes'] as $nome)
                            <x-ui.chip wire:key="agente-{{ $nome }}" :ativo="$agente === $nome" wire:click="$set('agente', @js($nome))">{{ $nome }}</x-ui.chip>
                        @endforeach
                    </div>
                </div>
            </x-ui.card>
            <p wire:loading class="text-sm text-on-surface-variant" role="status">Atualizando o mapa…</p>

            @if ($dados['nos'] === [])
                <x-ui.vazio icone="neurology" titulo="Nenhuma memória encontrada">Ajuste os filtros. Se o índice ainda estiver vazio, os aprendizados aparecerão depois da captura e indexação das sessões.</x-ui.vazio>
            @else
                <div class="grid items-start gap-4 xl:grid-cols-[minmax(0,1fr)_22rem]">
                    <x-grafico.cartao titulo="Mapa do cérebro"
                        :descricao="$mapa === null ? null : 'As '.count($mapa['nos']).' memórias mais recentes do filtro (de '.$mapa['encontrados'].' encontradas). Memórias parecidas ficam perto; o tamanho do ponto mostra quantas ligações ele tem e você pode arrastar as memórias para reorganizar o mapa. Cada memória mostra até '.\App\Rag\MapaConhecimento::LIGACOES_POR_NO.' ligações, as mais fortes.'"
                        :legenda="[['nome' => 'Memória', 'cor' => 'grafico-1'], ['nome' => 'Selecionada e ligações dela', 'cor' => 'grafico-2'], ['nome' => 'Ligação (mais grossa = mais parecida)', 'cor' => 'grafico-contexto', 'forma' => 'linha']]">
                        @if ($mapa === null)
                            <x-ui.aviso tipo="erro">Não foi possível montar o mapa agora. A lista de memórias abaixo continua disponível.</x-ui.aviso>
                        @else
                            <x-grafico.memoria wire:key="mapa-{{ md5($agente.'|'.$categoria.'|'.$busca.'|'.$limiar) }}"
                                :nos="$mapa['nos']" :conexoes="$mapa['conexoes']" :grupos="$mapa['grupos']" :selecionada="$memoria" :limiar="$limiarFracao" :categorias="$categorias" />
                        @endif
                        <x-slot:tabela>
                            @if (($mapa['conexoes'] ?? []) !== [])
                                <x-grafico.tabela :colunas="['Memória de origem', 'Memória de destino', 'Similaridade']"
                                    :linhas="array_map(fn (array $c): array => ['#'.$c['origem'], '#'.$c['destino'], $porcentagem($c['similaridade'])], $mapa['conexoes'])" />
                            @else
                                <p class="text-sm text-on-surface-variant">Nenhuma ligação atingiu {{ $limiar }}% de similaridade neste recorte.</p>
                            @endif
                        </x-slot:tabela>
                    </x-grafico.cartao>

                    <div class="flex flex-col gap-4">
                        <x-ui.card titulo="Memória selecionada" icone="description">
                            @if ($selecionada)
                                <div class="flex flex-col gap-3 text-sm">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <p class="font-medium text-on-surface">#{{ $selecionada['id'] }} · {{ $selecionada['agente'] }}</p>
                                        <x-ui.botao-icone icone="close" rotulo="Fechar a memória selecionada" wire:click="selecionar(0)" />
                                    </div>
                                    <div class="flex flex-wrap gap-2">
                                        <x-ui.etiqueta>{{ $categorias[$selecionada['categoria']] ?? $selecionada['categoria'] }}</x-ui.etiqueta>
                                        @if ($nosDoMapa->has($selecionada['id']))
                                            <x-ui.etiqueta cor="secundaria">Grupo {{ $nosDoMapa[$selecionada['id']]['grupo'] }} · {{ $nosDoMapa[$selecionada['id']]['grau'] }} ligações</x-ui.etiqueta>
                                        @endif
                                    </div>
                                    <p class="break-words text-xs text-on-surface-variant">{{ $selecionada['arquivo'] }} · {{ $selecionada['criado'] }}</p>
                                    <p class="max-h-72 overflow-y-auto whitespace-pre-wrap break-words text-on-surface">{{ $selecionada['conteudo'] }}</p>
                                </div>
                            @else
                                <p class="text-sm text-on-surface-variant">Selecione uma memória no mapa ou na lista para ler o conteúdo e seguir as ramificações dela.</p>
                            @endif
                        </x-ui.card>

                        @if ($selecionada)
                            <x-ui.card titulo="Ligações mais fortes" icone="share">
                                @if ($vizinhosDaSelecionada === [])
                                    <p class="text-sm text-on-surface-variant">
                                        @if ($nosDoMapa->has($selecionada['id']))
                                            Nenhuma memória do recorte chega a {{ $limiar }}% de similaridade com esta. Baixe a similaridade mínima para ver ligações mais fracas.
                                        @else
                                            Esta memória está fora do recorte do mapa. Ajuste os filtros para incluí-la.
                                        @endif
                                    </p>
                                @else
                                    <ul class="flex flex-col gap-1">
                                        @foreach ($vizinhosDaSelecionada as $vizinho)
                                            <li wire:key="vizinho-{{ $vizinho['id'] }}" class="flex items-center gap-3">
                                                <span class="w-14 shrink-0 text-right text-sm font-medium tabular-nums text-on-surface">{{ $porcentagem($vizinho['similaridade']) }}</span>
                                                <span class="min-w-0 flex-1">
                                                    <span class="block text-xs text-on-surface-variant">#{{ $vizinho['id'] }} · {{ $nosDoMapa[$vizinho['id']]['agente'] ?? '' }}</span>
                                                    <span class="block truncate text-sm text-on-surface" title="{{ $nosDoMapa[$vizinho['id']]['resumo'] ?? '' }}">{{ $nosDoMapa[$vizinho['id']]['resumo'] ?? '' }}</span>
                                                </span>
                                                <x-ui.botao-icone icone="arrow_forward" :rotulo="'Seguir para a memória #'.$vizinho['id']" wire:click="selecionar({{ $vizinho['id'] }})" />
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </x-ui.card>
                        @elseif (($mapa['grupos'] ?? []) !== [])
                            <x-ui.card titulo="Grupos de assunto" icone="bubble_chart">
                                <ul class="flex flex-col gap-2 text-sm">
                                    @foreach ($mapa['grupos'] as $grupo)
                                        <li wire:key="grupo-lista-{{ $grupo['id'] }}" class="flex items-baseline justify-between gap-3">
                                            <span class="min-w-0">
                                                <span class="font-medium text-on-surface">Grupo {{ $grupo['id'] }}</span>
                                                <span class="text-on-surface-variant">· {{ $categorias[$grupo['categoria']] ?? $grupo['categoria'] }} · {{ $grupo['agente'] }}</span>
                                            </span>
                                            <span class="shrink-0 tabular-nums text-on-surface-variant">{{ $grupo['total'] }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            </x-ui.card>
                        @endif
                    </div>
                </div>

                @if (count($arvore) > 1)
                    <x-grafico.cartao :titulo="'Ramificações da memória #'.$memoria"
                        :descricao="'No centro, a memória selecionada; no primeiro anel, as ligações diretas dela; no segundo, as ligações de cada uma que ainda não apareceram. Todas com similaridade de pelo menos '.$limiar.'%. Clique num ramo para seguir por ele.'"
                        :legenda="[['nome' => 'Memória central', 'cor' => 'grafico-2'], ['nome' => 'Ligação direta', 'cor' => 'grafico-1'], ['nome' => 'Segundo nível', 'cor' => 'grafico-contexto']]">
                        <div class="overflow-x-auto">
                            <x-grafico.ramificacoes :arvore="$arvore" :detalhes="$nosDoMapa->all()" :limiar="$limiarFracao" />
                        </div>
                        <x-slot:tabela>
                            <x-grafico.tabela :colunas="['Memória', 'Nível', 'Ligada a', 'Similaridade']"
                                :linhas="collect($arvore)->skip(1)->map(fn (array $no): array => ['#'.$no['id'].' · '.($nosDoMapa[$no['id']]['agente'] ?? ''), $no['nivel'] === 1 ? 'Direta' : 'Segundo nível', '#'.$no['pai'], $porcentagem((float) $no['similaridade'])])->values()->all()" />
                        </x-slot:tabela>
                    </x-grafico.cartao>
                @endif

                <x-ui.card titulo="Memórias do recorte" icone="list">
                    <p class="mb-3 text-xs text-on-surface-variant">Exibindo {{ count($dados['nos']) }} de {{ $dados['encontrados'] }} trechos encontrados, dos mais recentes aos mais antigos.</p>
                    <x-ui.paginacao :pagina="$dados['pagina']" :total-paginas="$dados['paginas']" :total="$dados['encontrados']" :por-pagina="24" />
                    <div class="grid gap-3 md:grid-cols-2 2xl:grid-cols-3">
                        @foreach ($dados['nos'] as $no)
                            <div wire:key="memoria-{{ $no['id'] }}" @class(['rounded-lg border p-3', 'border-primary' => $memoria === $no['id'], 'border-outline-variant' => $memoria !== $no['id']])>
                                <div class="flex items-center justify-between gap-2">
                                    <p class="text-xs text-on-surface-variant">#{{ $no['id'] }} · {{ $no['agente'] }}</p>
                                    <x-ui.botao variante="texto" wire:click="selecionar({{ $no['id'] }})" :aria-pressed="$memoria === $no['id'] ? 'true' : 'false'">Ler memória #{{ $no['id'] }}</x-ui.botao>
                                </div>
                                <p class="text-xs text-on-surface-variant">{{ $categorias[$no['categoria']] ?? $no['categoria'] }}</p>
                                <p class="mt-2 line-clamp-3 text-sm break-words text-on-surface">{{ $no['conteudo'] }}</p>
                            </div>
                        @endforeach
                    </div>
                </x-ui.card>
            @endif
        @endif
    </div>
</div>
