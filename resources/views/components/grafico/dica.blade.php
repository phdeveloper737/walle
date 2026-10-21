{{--
    Dica (tooltip) de um ponto do gráfico: aparece ao passar o mouse ou focar pelo teclado no "group" de fora.
    linhas: [['valor' => '1.284', 'nome' => 'Autorizadas', 'cor' => 'grafico-1'], ...]; lado: esquerda | meio | direita (para não sair do cartão).
--}}
@props([
    'titulo',
    'linhas' => [],
    'lado' => 'meio',
    'rodape' => null,
])

<div role="tooltip" @class([
    'pointer-events-none absolute bottom-full z-20 mb-2 hidden w-max max-w-56 rounded-sm bg-surface-container-highest px-3 py-2 text-xs shadow-lg shadow-black/40 group-hover:block group-focus-visible:block',
    'left-0' => $lado === 'esquerda',
    'right-0' => $lado === 'direita',
    'left-1/2 -translate-x-1/2' => $lado === 'meio',
])>
    <p class="mb-1 text-on-surface-variant">{{ $titulo }}</p>
    @foreach ($linhas as $linha)
        <p class="flex items-center gap-2 whitespace-nowrap">
            <span class="h-0.5 w-3 shrink-0 rounded-full" style="background: var(--color-{{ $linha['cor'] }})" aria-hidden="true"></span>
            <strong class="font-medium text-on-surface tabular-nums">{{ $linha['valor'] }}</strong>
            <span class="text-on-surface-variant">{{ $linha['nome'] }}</span>
        </p>
    @endforeach
    @if ($rodape)
        <p class="mt-1 text-on-surface-variant">{{ $rodape }}</p>
    @endif
</div>
