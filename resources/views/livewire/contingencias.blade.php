<div class="flex flex-col gap-3 lg:flex-row lg:items-start">
    {{-- Lista de clientes (Help Desk) --}}
    <section class="flex w-full flex-col gap-2 lg:sticky lg:top-17 lg:w-[340px] lg:shrink-0 2xl:w-[380px]" aria-label="Clientes">
        <div class="flex items-center gap-1">
            <x-ui.busca class="flex-1" placeholder="Buscar cliente, CNPJ ou #id" wire:model.live.debounce.250ms="busca" />
            <x-ui.botao-icone icone="refresh" rotulo="Atualizar a lista" wire:click="recarregar" wire:loading.attr="disabled" wire:target="recarregar" />
        </div>

        @if ($this->todosClientes === null)
            <x-ui.aviso tipo="erro">Não foi possível consultar o Help Desk (192.168.10.254). Tente atualizar em instantes.</x-ui.aviso>
        @else
            <x-ui.segmentado compacto wire:model.live="filtro" :opcoes="$this->opcoesFiltro" />

            <p class="px-1 text-xs text-on-surface-variant">
                {{ count($this->clientes) }} {{ count($this->clientes) === 1 ? 'cliente' : 'clientes' }}
            </p>

            <div class="flex flex-col gap-1.5 transition-opacity lg:max-h-[calc(100vh-13.5rem)] lg:overflow-y-auto lg:pr-1"
                wire:loading.class="opacity-60" wire:target="busca, filtro, recarregar">
                @forelse ($this->clientes as $cliente)
                    @php $selecionado = $cliente->id === $clienteId; @endphp
                    <button type="button" wire:key="cliente-{{ $cliente->id }}" wire:click="selecionar({{ $cliente->id }})"
                        wire:loading.attr="disabled" wire:target="selecionar"
                        {{-- No celular o detalhe fica embaixo da lista: rola até ele --}}
                        x-on:click="if (window.innerWidth < 1024) setTimeout(() => document.getElementById('detalhe-cliente')?.scrollIntoView({ behavior: 'smooth' }), 200)"
                        @if ($selecionado) aria-current="true" @endif
                        @class([
                            'relative w-full shrink-0 overflow-hidden rounded-md px-3 py-2.5 text-left transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:cursor-wait',
                            'before:absolute before:inset-0 before:bg-on-surface before:opacity-0 before:transition-opacity hover:before:opacity-8',
                            'bg-secondary-container text-on-secondary-container' => $selecionado,
                            'bg-surface-container-high' => ! $selecionado,
                        ])>
                        <span class="relative flex items-start justify-between gap-3">
                            <span class="font-medium break-words">{{ $cliente->razaoSocial }}</span>
                            <x-ui.etiqueta :cor="$cliente->totalContingencias > 0 ? 'erro' : 'neutra'" title="Contingências pendentes">
                                <x-ui.icone nome="receipt_long" class="text-[14px]" />{{ $cliente->totalContingencias }}
                            </x-ui.etiqueta>
                        </span>
                        <span @class(['relative mt-1.5 flex flex-wrap items-center gap-1.5 text-xs', 'text-on-surface-variant' => ! $selecionado])>
                            <span>#{{ $cliente->id }}</span>
                            <span aria-hidden="true">·</span>
                            <span>{{ $cliente->cnpj }}</span>
                            <x-ui.etiqueta>{{ $cliente->tipoPdv }}</x-ui.etiqueta>
                            @if ($cliente->servidorLocal)
                                <x-ui.etiqueta cor="primaria">SRV LOCAL</x-ui.etiqueta>
                            @endif
                        </span>
                    </button>
                @empty
                    <x-ui.vazio icone="search_off" titulo="Nenhum cliente encontrado">Tente outro termo ou outro filtro.</x-ui.vazio>
                @endforelse
            </div>
        @endif
    </section>

    {{-- Cliente escolhido --}}
    <section id="detalhe-cliente" class="min-w-0 flex-1 scroll-mt-16 rounded-lg bg-surface-container-low p-3 sm:p-4" aria-label="Cliente escolhido">
        <div wire:loading wire:target="selecionar" class="w-full">
            @include('livewire.contingencias.do-cliente-carregando')
        </div>
        <div wire:loading.remove wire:target="selecionar">
        @if ($cliente = $this->clienteSelecionado)
            <header class="flex flex-col gap-2 border-b border-outline-variant pb-3">
                <div>
                    <p class="text-sm text-secondary">Cliente #{{ $cliente->id }}</p>
                    <h2 class="text-2xl text-on-surface">{{ $cliente->razaoSocial }}</h2>
                </div>
                <div class="flex flex-wrap items-center gap-2 text-sm text-on-surface-variant">
                    <x-ui.etiqueta>CNPJ {{ $cliente->cnpj }}</x-ui.etiqueta>
                    @if ($cliente->loja !== '')
                        <x-ui.etiqueta>Loja {{ $cliente->loja }}</x-ui.etiqueta>
                    @endif
                    <x-ui.etiqueta>{{ $cliente->tipoPdv }}</x-ui.etiqueta>
                    @if ($cliente->servidorLocal)
                        <x-ui.etiqueta cor="secundaria">SRV LOCAL</x-ui.etiqueta>
                    @endif
                    <x-ui.etiqueta>{{ $cliente->consultoria ? 'Consultoria' : 'Outros' }}</x-ui.etiqueta>
                    @if ($cliente->ultimaAtualizacao)
                        <span>ERP atualizado em {{ $cliente->ultimaAtualizacao }}</span>
                    @endif
                </div>
            </header>

            <p class="mt-2 mb-3 text-xs text-on-surface-variant">
                Help Desk: {{ $cliente->totalContingencias }} pendentes informadas pelo PDV Linux + Versatus.
            </p>

            <livewire:contingencias.do-cliente :cliente-id="$cliente->id" :key="'do-cliente-'.$cliente->id" />
        @else
            <x-ui.vazio icone="storefront" titulo="Escolha um cliente">
                Selecione um cliente na lista para ver as contingências dele.
            </x-ui.vazio>
        @endif
        </div>
    </section>
</div>
