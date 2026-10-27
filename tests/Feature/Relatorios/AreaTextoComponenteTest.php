<?php

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;

test('area de texto renderiza textarea com rotulo e repassa atributos', function () {
    View::share('errors', new ViewErrorBag);

    $html = Blade::render('<x-ui.area-texto label="Observações" wire:model="obs" maxlength="200" rows="6" />');

    expect($html)
        ->toContain('<textarea')
        ->toContain('wire:model="obs"')
        ->toContain('maxlength="200"')
        ->toContain('rows="6"')
        ->toContain('id="obs"')
        ->toContain('for="obs"')
        ->toContain('Observações');
});

test('area de texto mostra a mensagem de erro e a ajuda', function () {
    $modelo = '<x-ui.area-texto label="Observações" wire:model="obs" ajuda="Até 200 caracteres." />';

    View::share('errors', (new ViewErrorBag)->put('default', new MessageBag(['obs' => ['Informe as observações.']])));
    $comErro = Blade::render($modelo);

    View::share('errors', new ViewErrorBag);
    $comAjuda = Blade::render($modelo);

    expect($comErro)
        ->toContain('Informe as observações.')
        ->toContain('aria-invalid="true"')
        ->toContain('aria-describedby="obs-apoio"')
        ->not->toContain('Até 200 caracteres.')
        ->and($comAjuda)->toContain('Até 200 caracteres.');
});

test('area de texto preserva as quebras de linha do valor', function () {
    View::share('errors', new ViewErrorBag);

    $html = Blade::render('<x-ui.area-texto label="Texto" name="texto" :valor="$valor" />', ['valor' => "linha 1\nlinha 2"]);

    expect($html)->toContain("linha 1\nlinha 2");
});
