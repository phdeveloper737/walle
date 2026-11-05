<?php

use Illuminate\Support\Facades\Blade;

$modelo = '<x-ui.modal :aberta="$aberta" titulo="Erros do ciclo" icone="error" fechar="fecharErros" largura="640px">Conteúdo da janela<x-slot:rodape>Rodapé da janela</x-slot:rodape></x-ui.modal>';

test('modal fechada nao renderiza a janela', function () use ($modelo) {
    $html = Blade::render($modelo, ['aberta' => false]);

    expect($html)->not->toContain('role="dialog"')->not->toContain('Conteúdo da janela');
});

test('modal aberta renderiza titulo, conteudo, rodape e atributos de acessibilidade', function () use ($modelo) {
    $html = Blade::render($modelo, ['aberta' => true]);

    expect($html)
        ->toContain('role="dialog"')
        ->toContain('aria-modal="true"')
        ->toContain('Erros do ciclo')
        ->toContain('Conteúdo da janela')
        ->toContain('Rodapé da janela')
        ->toContain('max-width: 640px');

    preg_match('/aria-labelledby="([^"]+)"/', $html, $achou);
    expect($html)->toContain('id="'.$achou[1].'"');
});

test('modal chama o metodo informado ao fechar pelo botao, Esc e clique fora', function () use ($modelo) {
    $html = Blade::render($modelo, ['aberta' => true]);

    expect($html)
        ->toContain('aria-label="Fechar"')
        ->toContain('x-on:click="$wire.fecharErros()"')
        ->toContain('x-on:keydown.escape.window="$wire.fecharErros()"')
        ->toContain('x-on:click.self="$wire.fecharErros()"');
});

test('modal sem rodape nao mostra a area do rodape', function () {
    $html = Blade::render('<x-ui.modal :aberta="true" titulo="Aviso" fechar="fechar">Texto</x-ui.modal>');

    expect($html)->toContain('Texto')->not->toContain('p-6 pt-3');
});
