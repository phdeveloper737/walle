<div>
    <div class="flex flex-col gap-4">
        <p class="text-sm text-on-surface-variant">Explore os aprendizados dos agentes. As conexões indicam semelhança de conteúdo, não relações de causa ou regras do projeto.</p>
        @if ($dados['erro'] ?? false)
            <x-ui.aviso tipo="erro">O índice do RAG não está disponível. Confira o contêiner walle-rag e tente atualizar a página.</x-ui.aviso>
        @else
            <div class="grid gap-3 sm:grid-cols-3">
                <x-ui.kpi rotulo="Documentos indexados" :valor="$dados['documentos']" icone="description" />
                <x-ui.kpi rotulo="Trechos de memória" :valor="$dados['total']" icone="neurology" />
                <x-ui.kpi rotulo="Agentes com memórias" :valor="count($dados['agentes'])" icone="smart_toy" />
            </div>
            <p class="text-xs text-on-surface-variant">Última indexação: {{ $dados['ultima'] ?? 'Nenhuma ainda' }} · Horário de Brasília. Os números descrevem o índice salvo; não confirmam a saúde do modelo.</p>
            <x-ui.card titulo="Filtrar memórias" icone="filter_alt">
                <div class="flex flex-col gap-4">
                    <x-ui.busca wire:model.live.debounce.400ms="busca" placeholder="Buscar no conteúdo das memórias" maxlength="200" />
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
            <p wire:loading class="text-sm text-on-surface-variant" role="status">Atualizando memórias…</p>
            @if ($dados['nos'] === [])
                <x-ui.vazio icone="neurology" titulo="Nenhuma memória encontrada">Ajuste os filtros. Se o índice ainda estiver vazio, os aprendizados aparecerão depois da captura e indexação das sessões.</x-ui.vazio>
            @else
                @php
                    $selecionada = collect($dados['nos'])->firstWhere('id', $memoria);
                    $linhas = array_map(fn ($c) => ['#'.$c['origem'], '#'.$c['destino'], number_format($c['similaridade'] * 100, 1, ',', '.').'%'], $dados['conexoes']);
                @endphp
                <div class="grid items-start gap-4 xl:grid-cols-3">
                    <div class="min-w-0 xl:col-span-2">
                        <x-grafico.cartao titulo="Conexões entre memórias" descricao="Até 24 trechos por página, dos mais recentes aos mais antigos, e as 120 conexões mais fortes da página, com similaridade de pelo menos 65%. A posição circular organiza a leitura; a distância não mede semelhança. Selecione um nó ou uma memória abaixo."
                            :legenda="[['nome' => 'Memória', 'cor' => 'grafico-1'], ['nome' => 'Selecionada', 'cor' => 'grafico-2'], ['nome' => 'Conexão', 'cor' => 'grafico-contexto', 'forma' => 'linha']]">
                            <div class="overflow-x-auto">
                                <x-grafico.memoria :nos="$dados['nos']" :conexoes="$dados['conexoes']" :selecionada="$memoria" />
                            </div>
                            <x-slot:tabela>
                                @if ($linhas)
                                    <x-grafico.tabela :colunas="['Memória de origem', 'Memória de destino', 'Similaridade']" :linhas="$linhas" />
                                @else
                                    <p class="text-sm text-on-surface-variant">Nenhuma conexão atingiu 65% de similaridade neste recorte.</p>
                                @endif
                            </x-slot:tabela>
                        </x-grafico.cartao>
                    </div>
                    <x-ui.card titulo="Memória selecionada" icone="description">
                        @if ($selecionada)
                            <div class="flex flex-col gap-3 text-sm">
                                <p class="font-medium text-on-surface">#{{ $selecionada['id'] }} · {{ $selecionada['agente'] }}</p>
                                <x-ui.etiqueta>{{ $categorias[$selecionada['categoria']] ?? $selecionada['categoria'] }}</x-ui.etiqueta>
                                <p class="break-words text-xs text-on-surface-variant">{{ $selecionada['arquivo'] }} · {{ $selecionada['criado'] }}</p>
                                <p class="whitespace-pre-wrap break-words text-on-surface">{{ $selecionada['conteudo'] }}</p>
                            </div>
                        @else
                            <p class="text-sm text-on-surface-variant">Selecione uma memória para ler o conteúdo e sua origem.</p>
                        @endif
                    </x-ui.card>
                </div>
                <x-ui.card titulo="Memórias do recorte" icone="hub">
                    <p class="mb-3 text-xs text-on-surface-variant">Exibindo {{ count($dados['nos']) }} de {{ $dados['encontrados'] }} trechos encontrados. Use as páginas e os filtros para explorar os demais. Os números identificam os nós e as conexões na tabela do gráfico.</p>
                    <x-ui.paginacao :pagina="$dados['pagina']" :total-paginas="$dados['paginas']" :total="$dados['encontrados']" :por-pagina="24" />
                    <div class="grid gap-3 md:grid-cols-2">
                        @foreach ($dados['nos'] as $no)
                            <div wire:key="memoria-{{ $no['id'] }}" class="rounded-lg border border-outline-variant p-3">
                                <div class="flex items-center justify-between gap-2">
                                    <p class="text-xs text-on-surface-variant">#{{ $no['id'] }} · {{ $no['agente'] }}</p>
                                    <x-ui.botao variante="texto" wire:click="$set('memoria', {{ $no['id'] }})" :aria-pressed="$memoria === $no['id'] ? 'true' : 'false'">Ler memória #{{ $no['id'] }}</x-ui.botao>
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
