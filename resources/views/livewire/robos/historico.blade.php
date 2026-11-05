<div class="flex flex-col gap-3">
    <x-ui.card titulo="Filtros" icone="filter_list">
        <div class="flex flex-col gap-3">
            <div class="overflow-x-auto">
                <x-ui.segmentado compacto wire:model.live="periodo" :opcoes="\App\Livewire\Robos\Historico::PERIODOS" class="min-w-max" />
            </div>

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <x-ui.campo label="Parâmetro" icone="tune" wire:model.live.debounce.300ms="parametro"
                    ajuda="Parte do nome do parâmetro." />
                <x-ui.segmentado wire:model.live="acao" :opcoes="['' => 'Todas'] + \App\Livewire\Robos\Historico::ACOES" />
                <x-ui.busca placeholder="Ator" wire:model.live.debounce.300ms="ator" />
            </div>
        </div>
    </x-ui.card>

    @php $paginacao = $this->historico; @endphp
    @if ($paginacao === null)
        <x-ui.aviso tipo="erro">Não foi possível ler o histórico de configuração. Tente de novo em instantes.</x-ui.aviso>
    @else
        <x-ui.card titulo="Alterações de configuração" icone="history">
            <x-ui.tabela vazio="Nenhuma alteração no período" vazioIcone="history" largura="900px">
                <x-slot:explicacao>As edições, ativações e desativações feitas nesta tela aparecem aqui.</x-slot:explicacao>
                <x-slot:cabecalho>
                    <x-ui.tabela.coluna>Data e hora</x-ui.tabela.coluna>
                    <x-ui.tabela.coluna>Parâmetro</x-ui.tabela.coluna>
                    <x-ui.tabela.coluna>Ação</x-ui.tabela.coluna>
                    <x-ui.tabela.coluna>Valor anterior</x-ui.tabela.coluna>
                    <x-ui.tabela.coluna>Valor novo</x-ui.tabela.coluna>
                    <x-ui.tabela.coluna>Ator</x-ui.tabela.coluna>
                    <x-ui.tabela.coluna>IP</x-ui.tabela.coluna>
                </x-slot:cabecalho>
                @foreach ($paginacao as $registro)
                    <x-ui.tabela.linha wire:key="historico-{{ $registro->id }}">
                        <x-ui.tabela.celula>{{ $registro->criado_em->format('d/m/Y H:i:s') }}</x-ui.tabela.celula>
                        <x-ui.tabela.celula destaque>
                            {{ $registro->parametro }}
                        </x-ui.tabela.celula>
                        <x-ui.tabela.celula>
                            <x-ui.etiqueta :cor="$registro->acao === 'alterar' ? 'secundaria' : 'primaria'">
                                {{ \App\Livewire\Robos\Historico::ACOES[$registro->acao] ?? $registro->acao }}
                            </x-ui.etiqueta>
                        </x-ui.tabela.celula>
                        <x-ui.tabela.celula>{{ $registro->valor_anterior_sanitizado !== null && $registro->valor_anterior_sanitizado !== '' ? $registro->valor_anterior_sanitizado : '—' }}</x-ui.tabela.celula>
                        <x-ui.tabela.celula>{{ $registro->valor_novo_sanitizado !== null && $registro->valor_novo_sanitizado !== '' ? $registro->valor_novo_sanitizado : '—' }}</x-ui.tabela.celula>
                        <x-ui.tabela.celula>{{ $registro->ator }}</x-ui.tabela.celula>
                        <x-ui.tabela.celula>{{ $registro->ip ?? '—' }}</x-ui.tabela.celula>
                    </x-ui.tabela.linha>
                @endforeach
            </x-ui.tabela>

            <x-ui.paginacao :pagina="$paginacao->currentPage()" :total-paginas="$paginacao->lastPage()"
                :total="$paginacao->total()" :por-pagina="$paginacao->perPage()" />
        </x-ui.card>
    @endif
</div>
