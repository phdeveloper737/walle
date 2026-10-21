{{--
    Moldura de um gráfico: título, legenda (2+ séries) e o botão que troca o gráfico pela tabela com os mesmos números.
    legenda: [['nome' => 'Autorizadas', 'cor' => 'grafico-1', 'forma' => 'barra'|'linha'], ...]
    Ex.: <x-grafico.cartao titulo="Volume por semana" :legenda="$legenda"> gráfico <x-slot:tabela> <table>… </x-slot:tabela> </x-grafico.cartao>
--}}
@props([
    'titulo',
    'descricao' => null,
    'legenda' => [],
])

<figure x-data="{ tabela: false }" {{ $attributes->class('flex min-w-0 flex-col rounded-lg bg-surface-container-low p-4') }}>
    <figcaption class="flex items-start gap-2">
        <div class="min-w-0">
            <h3 class="font-medium text-on-surface">{{ $titulo }}</h3>
            @if ($descricao)
                <p class="mt-0.5 text-xs text-on-surface-variant">{{ $descricao }}</p>
            @endif
        </div>

        @isset($tabela)
            <button type="button" x-on:click="tabela = ! tabela" x-bind:aria-pressed="tabela"
                x-bind:title="tabela ? 'Ver o gráfico' : 'Ver a tabela'" x-bind:aria-label="tabela ? 'Ver o gráfico' : 'Ver a tabela'"
                class="relative -mt-1.5 -mr-1.5 ml-auto flex size-9 shrink-0 items-center justify-center overflow-hidden rounded-full text-on-surface-variant transition before:absolute before:inset-0 before:bg-current before:opacity-0 before:transition-opacity hover:before:opacity-8">
                <span class="material-symbols-outlined relative text-[20px]" aria-hidden="true" x-text="tabela ? 'bar_chart' : 'table'">table</span>
            </button>
        @endisset
    </figcaption>

    @if (count($legenda) >= 2)
        <ul class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-on-surface-variant" x-show="! tabela">
            @foreach ($legenda as $item)
                <li class="flex items-center gap-1.5">
                    @if (($item['forma'] ?? 'barra') === 'linha')
                        <span class="h-0.5 w-4 rounded-full" style="background: var(--color-{{ $item['cor'] }})" aria-hidden="true"></span>
                    @else
                        <span class="size-2.5 rounded-[3px]" style="background: var(--color-{{ $item['cor'] }})" aria-hidden="true"></span>
                    @endif
                    {{ $item['nome'] }}
                </li>
            @endforeach
        </ul>
    @endif

    <div class="mt-3" x-show="! tabela">{{ $slot }}</div>

    @isset($tabela)
        <div class="mt-3 max-h-80 overflow-auto" x-show="tabela" x-cloak>{{ $tabela }}</div>
    @endisset
</figure>
