<div class="flex flex-col gap-3">
    @if ($analise === null)
        <x-ui.vazio icone="query_stats" titulo="Nenhuma análise de concorrentes ainda">
            A análise é gerada pelo squad de análise competitiva e gravada em app/Concorrentes/analise-concorrentes.json.
        </x-ui.vazio>
    @else
        <x-ui.abas :abas="\App\Livewire\Concorrentes::ABAS" :atual="$aba" />

        <p class="text-sm text-on-surface-variant">
            Análise de {{ $analise['atualizada_em']->format('d/m/Y') }} · {{ $analise['escopo'] }}.
            Dados públicos; o que vem do site do fornecedor é autodeclarado.
        </p>

        @switch($aba)
            @case('concorrentes')
                @include('livewire.concorrentes.fichas')
                @break
            @case('matriz')
                @include('livewire.concorrentes.matriz')
                @break
            @case('oportunidades')
                @include('livewire.concorrentes.oportunidades')
                @break
            @default
                @include('livewire.concorrentes.resumo')
        @endswitch
    @endif
</div>
