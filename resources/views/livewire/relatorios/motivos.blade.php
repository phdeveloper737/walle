@php
    $motivos = $this->motivos;
    $itens = array_map(fn (array $m): array => ['rotulo' => $m['motivo'], 'valores' => [$m['total']]], $motivos ?? []);
    $linhas = array_map(fn (array $m): array => [$m['motivo'], \App\Support\Numero::inteiro($m['total'])], $motivos ?? []);
    $clientesDoMotivo = $this->clientesDoMotivo;
@endphp

{{-- Filtro de período: numa linha acima de tudo o que ele recorta --}}
<div class="flex flex-wrap items-start gap-3">
    <div class="w-44"><x-ui.campo label="De" tipo="date" wire:model.live="de" /></div>
    <div class="w-44"><x-ui.campo label="Até" tipo="date" wire:model.live="ate" /></div>
    <x-ui.segmentado class="max-w-md flex-1" wire:model.live="tipo" :opcoes="['N' => 'Kits automáticos: não', 'S' => 'Kits automáticos: sim', '' => 'Todos']" />
</div>

@if ($motivos === null)
    <x-ui.aviso tipo="erro">O Help Desk (192.168.10.254) não respondeu e a lista de clientes não pôde ser conferida. Atualize a tela em instantes.</x-ui.aviso>
@elseif ($motivos === [])
    <x-ui.vazio icone="bar_chart" titulo="Nenhum motivo de contingência no período">
        Nenhuma contingência foi registrada entre as datas escolhidas. Mude o período ou o tipo de cliente.
    </x-ui.vazio>
@else
    <x-grafico.cartao titulo="Contingências por motivo" descricao="{{ count($motivos) }} {{ count($motivos) === 1 ? 'motivo' : 'motivos' }} no período escolhido.">
        <x-grafico.barras :itens="$itens" :series="[['nome' => 'Contingências', 'cor' => 'grafico-1']]" />
        <x-slot:tabela>
            <x-grafico.tabela :colunas="['Motivo', 'Contingências']" :linhas="$linhas" />
        </x-slot:tabela>
    </x-grafico.cartao>

    <x-ui.card titulo="Ranking de motivos" icone="bar_chart">
        <x-ui.tabela largura="600px">
            <x-slot:cabecalho>
                <x-ui.tabela.coluna>Motivo</x-ui.tabela.coluna>
                <x-ui.tabela.coluna numero>Contingências</x-ui.tabela.coluna>
                <x-ui.tabela.coluna numero>Ações</x-ui.tabela.coluna>
            </x-slot:cabecalho>

            @foreach ($motivos as $m)
                <x-ui.tabela.linha wire:key="motivo-{{ $loop->index }}">
                    <x-ui.tabela.celula destaque>{{ $m['motivo'] }}</x-ui.tabela.celula>
                    <x-ui.tabela.celula numero>{{ \App\Support\Numero::inteiro($m['total']) }}</x-ui.tabela.celula>
                    <x-ui.tabela.celula numero>
                        <x-ui.botao-icone icone="groups" rotulo="Ver clientes de {{ $m['motivo'] }}" wire:click="selecionarMotivo({{ \Illuminate\Support\Js::from($m['motivo']) }})" />
                    </x-ui.tabela.celula>
                </x-ui.tabela.linha>
            @endforeach
        </x-ui.tabela>
    </x-ui.card>
@endif

@if ($motivo !== '')
    <x-ui.modal :aberta="true" :titulo="$motivo" icone="bar_chart" fechar="limparMotivo" largura="720px">
        @if ($clientesDoMotivo === null)
            <x-ui.aviso tipo="erro">O Help Desk (192.168.10.254) não respondeu e os clientes deste motivo não puderam ser carregados. Atualize a tela em instantes.</x-ui.aviso>
        @elseif ($clientesDoMotivo === [])
            <x-ui.vazio icone="groups" titulo="Nenhum cliente com este motivo no período">
                Não há clientes registrados para o motivo selecionado com os filtros atuais.
            </x-ui.vazio>
        @else
            @php
                $qtdClientes = count($clientesDoMotivo);
                $totalContingencias = array_sum(array_column($clientesDoMotivo, 'total'));
                $textoClientes = $qtdClientes === 1 ? '1 cliente' : \App\Support\Numero::inteiro($qtdClientes) . ' clientes';
                $textoContingencias = $totalContingencias === 1 ? '1 contingência' : \App\Support\Numero::inteiro($totalContingencias) . ' contingências';
            @endphp
            <p class="mb-3 text-sm text-on-surface-variant">
                {{ $textoClientes }}, {{ $textoContingencias }} no período.
            </p>

            <x-ui.tabela largura="600px">
                <x-slot:cabecalho>
                    <x-ui.tabela.coluna>Cliente</x-ui.tabela.coluna>
                    <x-ui.tabela.coluna>CNPJ</x-ui.tabela.coluna>
                    <x-ui.tabela.coluna numero>Contingências</x-ui.tabela.coluna>
                </x-slot:cabecalho>

                @foreach ($clientesDoMotivo as $c)
                    <x-ui.tabela.linha wire:key="cliente-motivo-{{ $c['cliente'] }}">
                        <x-ui.tabela.celula destaque>
                            <a href="{{ route('relatorios', ['aba' => 'contingencia', 'cliente' => $c['cliente']]) }}" wire:navigate class="text-primary hover:underline">
                                {{ $c['nome'] }}
                            </a>
                        </x-ui.tabela.celula>
                        <x-ui.tabela.celula class="font-mono text-xs">{{ $c['cnpj'] }}</x-ui.tabela.celula>
                        <x-ui.tabela.celula numero>{{ \App\Support\Numero::inteiro($c['total']) }}</x-ui.tabela.celula>
                    </x-ui.tabela.linha>
                @endforeach
            </x-ui.tabela>
        @endif

        <x-slot:rodape>
            <x-ui.botao variante="texto" wire:click="limparMotivo">Fechar</x-ui.botao>
        </x-slot:rodape>
    </x-ui.modal>
@endif
