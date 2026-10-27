<?php

use App\Livewire\Crontab;
use App\Models\Usuario;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;
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

test('em requisição web, sem o console inicializado, as rotinas aparecem uma vez cada', function () {
    app()->forgetInstance(Schedule::class);
    app()->forgetInstance(ConsoleKernel::class);
    ScheduleFacade::clearResolvedInstance('Illuminate\Console\Scheduling\Schedule');
    expect(app(Schedule::class)->events())->toBeEmpty();

    $componente = Livewire::test(Crontab::class);
    $primeira = collect($componente->viewData('rotinas'))->pluck('nome');
    $segunda = collect($componente->call('$refresh')->viewData('rotinas'))->pluck('nome');

    expect($primeira)->toContain('walle:robo-autorizadas')
        ->and($primeira->duplicates())->toBeEmpty()
        ->and($segunda->all())->toBe($primeira->all());
});

test('rotina sem condição aparece como ligada', function () {
    $rotinas = Livewire::test(Crontab::class)->viewData('rotinas');

    expect(collect($rotinas)->firstWhere('nome', 'walle:batida-do-agendador')['ligada'])->toBeTrue();
});
