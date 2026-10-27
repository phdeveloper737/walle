<?php

use App\Livewire\Crontab;
use App\Models\Usuario;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(Usuario::find(criarUsuarioHelpDesk()));
});

test('as rotinas do agendador aparecem com a frequência certa', function () {
    Livewire::test(Crontab::class)
        ->assertOk()
        ->assertSeeInOrder(['walle:robo-autorizadas', 'a cada minuto'])
        ->assertSeeInOrder(['walle:robo-sactus', 'de hora em hora'])
        ->assertSeeInOrder(['walle:robo-sentinela', 'a cada 10 minutos'])
        ->assertSeeInOrder(['walle:robo-cleantax', 'a cada 30 segundos']);
});

test('rotina de segundos mostra os segundos e não o cron de todo minuto', function () {
    Livewire::test(Crontab::class)
        ->assertSeeInOrder(['walle:robo-contingencias', 'a cada 10 segundos']);
});

test('robô desligado aparece como desligado e ligado como ligado', function () {
    config(['walle.autorizadas.ativo' => false]);
    $desligada = Livewire::test(Crontab::class)->viewData('rotinas');
    expect(collect($desligada)->firstWhere('nome', 'walle:robo-autorizadas')['ligada'])->toBeFalse();

    config(['walle.autorizadas.ativo' => true]);
    $ligada = Livewire::test(Crontab::class)->viewData('rotinas');
    expect(collect($ligada)->firstWhere('nome', 'walle:robo-autorizadas')['ligada'])->toBeTrue();
});

test('rotina sem condição aparece como ligada', function () {
    $rotinas = Livewire::test(Crontab::class)->viewData('rotinas');

    expect(collect($rotinas)->firstWhere('nome', 'walle:batida-do-agendador')['ligada'])->toBeTrue();
});
