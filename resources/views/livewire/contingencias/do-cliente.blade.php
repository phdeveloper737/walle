<div class="flex flex-col gap-4">
    @if ($this->busca['erro'])
        <x-ui.aviso tipo="erro">{{ $this->busca['erro'] }}</x-ui.aviso>
        <div>
            <x-ui.botao variante="tonal" icone="refresh" wire:click="recarregar">Tentar de novo</x-ui.botao>
        </div>
    @else
        @php
            $resultado = $this->busca['resultado'];
            $sactus = $resultado->totalDo(\App\Contingencias\Contingencia::SACTUS);
            $versatus = $resultado->totalDo(\App\Contingencias\Contingencia::VERSATUS);
        @endphp

        {{-- Resumo --}}
        <div class="flex flex-wrap items-center gap-2">
            <x-ui.etiqueta :cor="$resultado->pendentes ? 'erro' : 'sucesso'">{{ count($resultado->pendentes) }} pendentes</x-ui.etiqueta>
            @if ($sactus)
                <x-ui.etiqueta>SACTUS {{ $sactus }}</x-ui.etiqueta>
            @endif
            @if ($versatus)
                <x-ui.etiqueta>VERSATUS {{ $versatus }}</x-ui.etiqueta>
            @endif
            <span class="ml-auto text-xs text-on-surface-variant">Consultado às {{ $resultado->consultadoEm }}</span>
            <x-ui.botao-icone icone="refresh" rotulo="Consultar de novo no servidor do cliente" wire:click="recarregar" wire:loading.attr="disabled" />
        </div>

        @if ($resultado->totalNoPdvSactus > $sactus)
            <p class="text-xs text-on-surface-variant">
                O PDV tem {{ number_format($resultado->totalNoPdvSactus, 0, ',', '.') }} contingências no Sactus; aparecem até 1.000, um pouco de cada tipo de erro (como no sistema antigo).
            </p>
        @endif

        @if ($resultado->avisoSactus)
            <x-ui.aviso>Sactus: {{ $resultado->avisoSactus }}</x-ui.aviso>
        @endif

        <div class="flex flex-col gap-3">
            <x-ui.segmentado class="sm:max-w-sm" wire:model.live="aba" :opcoes="[
                'pendentes' => 'Pendentes ('.count($resultado->pendentes).')',
                'autorizadas' => 'Autorizadas ('.count($resultado->autorizadas).')',
            ]" />
            <x-ui.busca class="flex-1" placeholder="Filtrar por cupom, produto, motivo, NCM…" wire:model.live.debounce.300ms="filtro" />
        </div>

        <div class="flex flex-col gap-3 transition-opacity" wire:loading.class="opacity-60" wire:target="aba, filtro, irParaPagina">
            @if ($aba === 'pendentes')
                @forelse ($itensDaPagina as $contingencia)
                    <article wire:key="cont-{{ $contingencia->identificador() }}" class="overflow-hidden rounded-md bg-surface-container-high">
                        <header class="flex flex-col gap-2 px-4 pt-3 pb-2">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <x-ui.etiqueta cor="secundaria">Loja {{ $contingencia->loja }}</x-ui.etiqueta>
                                <x-ui.etiqueta cor="secundaria">PDV {{ $contingencia->pdv }}</x-ui.etiqueta>
                                <x-ui.etiqueta cor="secundaria">Cupom {{ $contingencia->cupom }}</x-ui.etiqueta>
                                <x-ui.etiqueta>{{ $contingencia->data() }} {{ $contingencia->hora() }}</x-ui.etiqueta>
                                <x-ui.etiqueta>{{ $contingencia->software }}</x-ui.etiqueta>
                                @if ($comProblema = $contingencia->itensComInconsistencia())
                                    <x-ui.etiqueta cor="erro">{{ $comProblema }} {{ $comProblema === 1 ? 'item' : 'itens' }} com inconsistência</x-ui.etiqueta>
                                @endif
                            </div>
                            <p class="flex items-start gap-2 text-sm text-on-surface">
                                <x-ui.icone nome="warning" class="mt-0.5 text-[18px] text-secondary" />
                                <span class="break-words"><strong class="font-medium">Cód {{ $contingencia->codigoMotivo }}</strong> — {{ $contingencia->motivo }}</span>
                            </p>
                            @if ($contingencia->chaveNfce())
                                <p class="font-mono text-[11px] break-all text-on-surface-variant">{{ $contingencia->chaveNfce() }}</p>
                            @endif
                        </header>

                        <div class="overflow-x-auto">
                            <table class="w-full min-w-[1100px] text-left text-xs">
                                <thead class="bg-surface-container-highest/50 text-on-surface-variant">
                                    <tr>
                                        @foreach (['#', 'Cód barras', 'Id prod', 'Nome', 'Un', 'Alíq', 'Redução', 'Alíq red', 'NCM', 'CEST', 'cBenef', 'CFOP', 'CST', 'CST IBS/CBS', 'ClassTrib'] as $coluna)
                                            <th class="px-3 py-2 font-medium whitespace-nowrap">{{ $coluna }}</th>
                                        @endforeach
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($contingencia->itens as $indice => $item)
                                        @php $problemas = $item->inconsistencias(); @endphp
                                        <tr @class(['border-t border-outline-variant', 'bg-error-container/50 text-on-error-container' => $problemas])
                                            @if ($problemas) title="{{ implode(' ', $problemas) }}" @endif>
                                            <td class="px-3 py-1.5">{{ $indice + 1 }}</td>
                                            <td class="px-3 py-1.5">{{ $item->codigoBarras }}</td>
                                            <td class="px-3 py-1.5">{{ $item->idProduto }}</td>
                                            <td class="px-3 py-1.5">{{ $item->nome }}</td>
                                            <td class="px-3 py-1.5">{{ $item->unidade }}</td>
                                            <td class="px-3 py-1.5">{{ $item->aliquota }}</td>
                                            <td class="px-3 py-1.5">{{ $item->reducao }}</td>
                                            <td class="px-3 py-1.5">{{ $item->aliquotaReduzida }}</td>
                                            <td class="px-3 py-1.5">{{ $item->ncm }}</td>
                                            <td class="px-3 py-1.5">{{ $item->cest }}</td>
                                            <td class="px-3 py-1.5">{{ $item->cbenef ?: '—' }}</td>
                                            <td class="px-3 py-1.5">{{ $item->cfop }}</td>
                                            <td class="px-3 py-1.5">{{ $item->cst }}</td>
                                            <td class="px-3 py-1.5">{{ $item->cstIbsCbs }}</td>
                                            <td class="px-3 py-1.5">{{ $item->classTribIbs }}</td>
                                        </tr>
                                        @if ($problemas)
                                            <tr class="bg-error-container/50 text-on-error-container">
                                                <td></td>
                                                <td colspan="14" class="px-3 pb-2">
                                                    <span class="inline-flex items-center gap-1"><x-ui.icone nome="error" class="text-[14px]" />{{ implode(' · ', $problemas) }}</span>
                                                </td>
                                            </tr>
                                        @endif
                                    @empty
                                        <tr class="border-t border-outline-variant">
                                            <td colspan="15" class="px-3 py-2 text-on-surface-variant">Os itens desta venda não foram encontrados no PDV.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </article>
                @empty
                    @if (trim($filtro) !== '')
                        <x-ui.vazio icone="search_off" titulo="Nada com esse filtro">Tente outro termo.</x-ui.vazio>
                    @else
                        <x-ui.vazio icone="task_alt" titulo="Nenhuma contingência pendente">Tudo certo com este cliente.</x-ui.vazio>
                    @endif
                @endforelse
            @else
                @if ($itensDaPagina)
                    <div class="overflow-x-auto rounded-md bg-surface-container-high">
                        <table class="w-full min-w-[900px] text-left text-xs">
                            <thead class="text-on-surface-variant">
                                <tr>
                                    @foreach (['Chave de acesso', 'Loja', 'PDV', 'Cupom', 'Emissão', 'Software', 'Consulta do robô'] as $coluna)
                                        <th class="px-3 py-2 font-medium whitespace-nowrap">{{ $coluna }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($itensDaPagina as $autorizada)
                                    <tr wire:key="aut-{{ $autorizada->chave }}" class="border-t border-outline-variant">
                                        <td class="px-3 py-1.5 font-mono break-all">{{ $autorizada->chave }}</td>
                                        <td class="px-3 py-1.5">{{ $autorizada->contingencia->loja }}</td>
                                        <td class="px-3 py-1.5">{{ $autorizada->contingencia->pdv }}</td>
                                        <td class="px-3 py-1.5">{{ $autorizada->contingencia->cupom }}</td>
                                        <td class="px-3 py-1.5 whitespace-nowrap">{{ $autorizada->contingencia->data() }} {{ $autorizada->contingencia->hora() }}</td>
                                        <td class="px-3 py-1.5">{{ $autorizada->contingencia->software }}</td>
                                        <td class="px-3 py-1.5 whitespace-nowrap">{{ $autorizada->consultadaEmFormatada() }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <x-ui.vazio icone="verified" titulo="{{ trim($filtro) !== '' ? 'Nada com esse filtro' : 'Nenhuma autorizada encontrada' }}">
                        Aqui aparecem as contingências que o robô já viu autorizadas na Sefaz.
                    </x-ui.vazio>
                @endif
            @endif
        </div>

        <x-ui.paginacao :pagina="$pagina" :total-paginas="$totalPaginas" :total="$totalNaLista" :por-pagina="\App\Livewire\Contingencias\DoCliente::POR_PAGINA" />
    @endif
</div>
