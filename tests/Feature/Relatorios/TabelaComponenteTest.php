<?php

use Illuminate\Support\Facades\Blade;

test('tabela renderiza cabecalhos com scope, celulas e repassa atributos da linha', function () {
    $html = Blade::render(<<<'BLADE'
        <x-ui.tabela aria-label="Ciclos">
            <x-slot:cabecalho>
                <x-ui.tabela.coluna>Cliente</x-ui.tabela.coluna>
                <x-ui.tabela.coluna numero>Vendas</x-ui.tabela.coluna>
            </x-slot:cabecalho>
            <x-ui.tabela.linha wire:key="linha-7" data-teste="x">
                <x-ui.tabela.celula>Mercado Alfa</x-ui.tabela.celula>
                <x-ui.tabela.celula numero>1.284</x-ui.tabela.celula>
            </x-ui.tabela.linha>
        </x-ui.tabela>
    BLADE);

    expect($html)
        ->toContain('aria-label="Ciclos"')
        ->toContain('Mercado Alfa')
        ->toContain('1.284')
        ->toContain('wire:key="linha-7"')
        ->toContain('data-teste="x"')
        ->and(substr_count($html, 'scope="col"'))->toBe(2)
        ->and(substr_count($html, 'overflow-x-auto'))->toBe(1);
});

test('numero fica alinhado a direita e texto a esquerda, no cabecalho e na celula', function () {
    $html = Blade::render(<<<'BLADE'
        <x-ui.tabela.coluna>Nome</x-ui.tabela.coluna>
        <x-ui.tabela.coluna numero>Total</x-ui.tabela.coluna>
        <x-ui.tabela.celula>a</x-ui.tabela.celula>
        <x-ui.tabela.celula numero>1</x-ui.tabela.celula>
    BLADE);

    expect(substr_count($html, 'text-right'))->toBe(2)
        ->and(substr_count($html, 'text-left'))->toBe(2);
});

test('sem linhas mostra o estado vazio quando o titulo e informado', function () {
    $html = Blade::render(<<<'BLADE'
        <x-ui.tabela vazio="Nenhum ciclo ainda">
            <x-slot:cabecalho><x-ui.tabela.coluna>Ciclo</x-ui.tabela.coluna></x-slot:cabecalho>
            <x-slot:explicacao>O histórico começa no primeiro ciclo que rodar.</x-slot:explicacao>
            @foreach ([] as $nada)
                <x-ui.tabela.linha />
            @endforeach
        </x-ui.tabela>
    BLADE);

    expect($html)
        ->toContain('Nenhum ciclo ainda')
        ->toContain('O histórico começa no primeiro ciclo que rodar.')
        ->not->toContain('<table');
});

test('com linhas o estado vazio nao aparece', function () {
    $html = Blade::render(<<<'BLADE'
        <x-ui.tabela vazio="Nenhum ciclo ainda">
            <x-slot:cabecalho><x-ui.tabela.coluna>Ciclo</x-ui.tabela.coluna></x-slot:cabecalho>
            <x-ui.tabela.linha><x-ui.tabela.celula>#1</x-ui.tabela.celula></x-ui.tabela.linha>
        </x-ui.tabela>
    BLADE);

    expect($html)->toContain('#1')->toContain('<table')->not->toContain('Nenhum ciclo ainda');
});
