@php
    $situacoes = \App\Concorrentes\AnaliseConcorrentes::SITUACOES;
    $icones = ['sim' => ['check_circle', 'text-success'], 'parcial' => ['contrast', 'text-secondary'], 'nd' => ['help', 'text-on-surface-variant opacity-60']];
@endphp

<div class="flex flex-wrap gap-4 text-xs text-on-surface-variant" aria-label="Legenda">
    @foreach ($situacoes as $chave => $rotulo)
        <span class="flex items-center gap-1"><x-ui.icone :nome="$icones[$chave][0]" class="text-[18px] {{ $icones[$chave][1] }}" />{{ $rotulo }}</span>
    @endforeach
    <span class="flex items-center gap-1"><x-ui.etiqueta cor="primaria">lacuna</x-ui.etiqueta> só a JM divulga</span>
</div>

<section class="rounded-lg bg-surface-container-low p-4" aria-label="Matriz comparativa">
    <div class="overflow-x-auto">
        <table class="w-full min-w-[860px] text-sm">
            <thead>
                <tr class="text-xs text-on-surface-variant">
                    <th scope="col" class="border-b border-outline-variant px-2 py-1.5 text-left font-medium">Capacidade</th>
                    @foreach ($analise['matriz']['colunas'] as $chave => $rotulo)
                        <th scope="col" @class([
                            'border-b border-outline-variant px-2 py-1.5 text-center font-medium',
                            'rounded-t-md bg-primary-container text-on-primary-container' => $chave === 'jm',
                        ])>{{ $rotulo }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($analise['matriz']['linhas'] as $linha)
                    <tr class="border-b border-outline-variant/50 last:border-0" wire:key="linha-{{ $loop->index }}">
                        <th scope="row" class="px-2 py-2 text-left font-normal text-on-surface">
                            <span class="flex flex-wrap items-center gap-1.5">
                                {{ $linha['capacidade'] }}
                                @if ($linha['lacuna'])
                                    <x-ui.etiqueta cor="primaria">lacuna</x-ui.etiqueta>
                                @endif
                            </span>
                        </th>
                        @foreach ($analise['matriz']['colunas'] as $chave => $rotulo)
                            @php $celula = $linha['valores'][$chave]; @endphp
                            <td @class(['px-2 py-2 text-center', 'bg-surface-container-high' => $chave === 'jm'])>
                                <x-ui.icone :nome="$icones[$celula['situacao']][0]" class="text-[20px] {{ $icones[$celula['situacao']][1] }}" />
                                <span class="sr-only">{{ $rotulo }}: {{ $situacoes[$celula['situacao']] }}</span>
                                @if ($celula['nota'])
                                    <span class="block text-[11px] leading-tight text-on-surface-variant">{{ $celula['nota'] }}</span>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</section>

<div class="grid gap-3 md:grid-cols-3">
    @foreach (['ganha' => ['Onde a JM ganha', 'trending_up', 'text-success'], 'empata' => ['Onde empata', 'drag_handle', 'text-secondary'], 'perde' => ['Onde perde', 'trending_down', 'text-error']] as $chave => [$titulo, $icone, $cor])
        <x-ui.card :titulo="$titulo" :icone="$icone" wire:key="pos-{{ $chave }}">
            <ul class="flex flex-col gap-1.5 text-sm text-on-surface">
                @foreach ($analise['posicionamento'][$chave] as $item)
                    <li class="flex gap-2"><span class="{{ $cor }}">•</span><span>{{ $item }}</span></li>
                @endforeach
            </ul>
        </x-ui.card>
    @endforeach
</div>

<p class="text-xs text-on-surface-variant">
    “Não divulgado” quer dizer que a capacidade não aparece nas fontes públicas, não que o concorrente não tenha. Vale confirmar com o comercial.
</p>
