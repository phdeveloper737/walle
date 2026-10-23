@php
    $lacunas = collect($analise['matriz']['linhas'])->where('lacuna', true);
@endphp

<div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
    <x-ui.kpi rotulo="Concorrentes analisados" icone="storefront" :valor="count($analise['concorrentes'])" />
    <x-ui.kpi rotulo="Capacidades comparadas" icone="table_chart" :valor="count($analise['matriz']['linhas'])" />
    <x-ui.kpi rotulo="Lacunas que só a JM cobre" icone="target" :valor="$lacunas->count()" :detalhe="$lacunas->pluck('capacidade')->join(', ')" />
    <x-ui.kpi rotulo="Oportunidades priorizadas" icone="lightbulb" :valor="count($analise['oportunidades'])" />
</div>

<div class="grid gap-3 xl:grid-cols-2">
    <x-ui.card titulo="Conclusões" icone="summarize">
        <ol class="flex flex-col gap-3 text-sm text-on-surface">
            @foreach ($analise['resumo'] as $conclusao)
                <li class="flex gap-3" wire:key="resumo-{{ $loop->index }}">
                    <span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-primary text-xs font-medium text-on-primary">{{ $loop->iteration }}</span>
                    <span>{{ $conclusao }}</span>
                </li>
            @endforeach
        </ol>
    </x-ui.card>

    <div class="flex flex-col gap-3">
        <x-ui.card titulo="Mensagens para o comercial" icone="campaign">
            <ul class="flex flex-col gap-3">
                @foreach ($analise['mensagens'] as $mensagem)
                    <li class="rounded-md bg-surface-container-low px-4 py-3" wire:key="msg-{{ $loop->index }}">
                        <p class="font-medium text-on-surface">“{{ $mensagem['frase'] }}”</p>
                        <p class="mt-0.5 text-sm text-on-surface-variant">{{ $mensagem['apoio'] }}</p>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>

        <x-ui.card titulo="Ameaças" icone="warning">
            <ul class="flex flex-col gap-2 text-sm">
                @foreach ($analise['ameacas'] as $ameaca)
                    <li class="flex gap-2" wire:key="ameaca-{{ $loop->index }}">
                        <x-ui.icone nome="error" class="text-[18px] text-error" />
                        <span><span class="font-medium text-on-surface">{{ $ameaca['titulo'] }}:</span> <span class="text-on-surface-variant">{{ $ameaca['texto'] }}</span></span>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    </div>
</div>

<x-ui.card titulo="Próximos passos (90 dias)" icone="flag">
    <ol class="grid gap-2 text-sm md:grid-cols-2">
        @foreach ($analise['proximos_passos'] as $passo)
            <li class="flex gap-3 rounded-md bg-surface-container-low px-4 py-3" wire:key="passo-{{ $loop->index }}">
                <span class="font-medium text-primary">{{ $loop->iteration }}.</span>
                <span class="text-on-surface">{{ $passo }}</span>
            </li>
        @endforeach
    </ol>
</x-ui.card>
