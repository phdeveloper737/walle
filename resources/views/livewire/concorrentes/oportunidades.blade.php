@php
    $nivel = ['alto' => ['Alto', 'sucesso'], 'medio' => ['Médio', 'secundaria'], 'baixo' => ['Baixo', 'neutra']];
    $tipo = ['quick win' => ['Fazer já', 'sucesso'], 'aposta' => ['Planejar', 'primaria']];
@endphp

<section class="rounded-lg bg-surface-container-low p-4" aria-label="Oportunidades">
    <div class="overflow-x-auto">
        <table class="w-full min-w-[860px] text-sm">
            <thead>
                <tr class="text-xs text-on-surface-variant">
                    @foreach (['#', 'Oportunidade', 'Lacuna observada', 'Ativo da JM', 'Impacto', 'Esforço', 'Prazo', 'Tipo'] as $coluna)
                        <th scope="col" class="border-b border-outline-variant px-2 py-1.5 text-left font-medium">{{ $coluna }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($analise['oportunidades'] as $oportunidade)
                    <tr class="border-b border-outline-variant/50 align-top last:border-0" wire:key="op-{{ $oportunidade['codigo'] }}">
                        <td class="px-2 py-2 font-medium text-primary">{{ $oportunidade['codigo'] }}</td>
                        <td class="px-2 py-2">
                            <span class="font-medium text-on-surface">{{ $oportunidade['titulo'] }}</span>
                            <span class="block text-xs text-on-surface-variant">{{ $oportunidade['descricao'] }}</span>
                        </td>
                        <td class="px-2 py-2 text-on-surface-variant">{{ $oportunidade['lacuna'] }}</td>
                        <td class="px-2 py-2 text-on-surface-variant">{{ $oportunidade['ativo'] }}</td>
                        <td class="px-2 py-2"><x-ui.etiqueta :cor="$nivel[$oportunidade['impacto']][1]">{{ $nivel[$oportunidade['impacto']][0] }}</x-ui.etiqueta></td>
                        <td class="px-2 py-2"><x-ui.etiqueta :cor="$nivel[$oportunidade['esforco']][1]">{{ $nivel[$oportunidade['esforco']][0] }}</x-ui.etiqueta></td>
                        <td class="px-2 py-2 whitespace-nowrap text-on-surface-variant">{{ $oportunidade['prazo'] }}</td>
                        <td class="px-2 py-2"><x-ui.etiqueta :cor="$tipo[$oportunidade['tipo']][1]">{{ $tipo[$oportunidade['tipo']][0] }}</x-ui.etiqueta></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</section>

<h2 class="mt-2 text-base font-medium text-on-surface">Defesas: fraquezas da JM para cobrir</h2>
<div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
    @foreach ($analise['defesas'] as $defesa)
        <x-ui.card :titulo="$defesa['codigo'].' · '.$defesa['titulo']" icone="shield" wire:key="def-{{ $defesa['codigo'] }}">
            <p class="text-sm text-on-surface-variant">{{ $defesa['acao'] }}</p>
        </x-ui.card>
    @endforeach
</div>
