@php
    $motivos = $this->motivos;
    $itens = array_map(fn (array $m): array => ['rotulo' => $m['motivo'], 'valores' => [$m['total']]], $motivos);
    $linhas = array_map(fn (array $m): array => [$m['motivo'], \App\Support\Numero::inteiro($m['total'])], $motivos);
@endphp

{{-- Filtro de período: numa linha acima de tudo o que ele recorta --}}
<div class="flex flex-wrap items-start gap-3">
    <div class="w-44"><x-ui.campo label="De" tipo="date" wire:model.live="de" /></div>
    <div class="w-44"><x-ui.campo label="Até" tipo="date" wire:model.live="ate" /></div>
    <x-ui.segmentado class="max-w-md flex-1" wire:model.live="tipo" :opcoes="['N' => 'Kits automáticos: não', 'S' => 'Kits automáticos: sim', '' => 'Todos']" />
</div>

@if ($motivos === [])
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
@endif
