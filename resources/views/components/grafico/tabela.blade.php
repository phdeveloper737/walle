{{-- Tabela com os mesmos números do gráfico (a versão acessível dele). colunas: ['Semana', 'Autorizadas', ...]; linhas: [['01/09', '1.284', ...], ...] --}}
@props([
    'colunas' => [],
    'linhas' => [],
])

<table class="w-full text-sm">
    <thead class="sticky top-0 bg-surface-container-low">
        <tr>
            @foreach ($colunas as $i => $coluna)
                <th scope="col" @class(['border-b border-outline-variant px-2 py-1.5 text-xs font-medium text-on-surface-variant', 'text-left' => $i === 0, 'text-right' => $i > 0])>{{ $coluna }}</th>
            @endforeach
        </tr>
    </thead>
    <tbody>
        @foreach ($linhas as $linha)
            <tr class="border-b border-outline-variant/50 last:border-0">
                @foreach ($linha as $i => $celula)
                    <td @class(['px-2 py-1.5', 'text-on-surface' => $i === 0, 'text-right text-on-surface-variant tabular-nums' => $i > 0])>{{ $celula }}</td>
                @endforeach
            </tr>
        @endforeach
    </tbody>
</table>
