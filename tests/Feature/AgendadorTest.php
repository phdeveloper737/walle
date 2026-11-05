<?php

use App\Support\ConfiguracaoRobo;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;

function eventoDaAgenda(string $nome): ?Event
{
    foreach (app(Schedule::class)->events() as $evento) {
        if ($evento->description === $nome) {
            return $evento;
        }
    }

    return null;
}

dataset('robos da agenda', [
    'contingencias' => ['walle:robo-contingencias', 'contingencias', '* * * * *', 10],
    'sentinela' => ['walle:robo-sentinela', 'sentinela', '*/10 * * * *', null],
    'cleantax' => ['walle:robo-cleantax', 'cleantax', '* * * * *', 30],
    'tarefas' => ['walle:robo-tarefas', 'tarefas', '* * * * *', null],
]);

test('o robô desligado (padrão) não é devido na agenda', function (string $nome, string $bloco) {
    config()->set("walle.{$bloco}.ativo", false);

    $evento = eventoDaAgenda($nome);

    expect($evento)->not->toBeNull()
        ->and($evento->filtersPass($this->app))->toBeFalse();
})->with('robos da agenda');

test('o robô ligado (.env e chave local) é devido na agenda', function (string $nome, string $bloco, string $cron, ?int $segundos) {
    config()->set("walle.{$bloco}.ativo", true);
    ConfiguracaoRobo::definirAtivo($bloco, true, 'teste');

    $evento = eventoDaAgenda($nome);

    expect($evento->filtersPass($this->app))->toBeTrue()
        ->and($evento->expression)->toBe($cron)
        ->and($evento->repeatSeconds)->toBe($segundos);
})->with('robos da agenda');

test('o robô com o .env ligado mas sem a chave local não é devido na agenda', function (string $nome, string $bloco) {
    config()->set("walle.{$bloco}.ativo", true);

    expect(eventoDaAgenda($nome)->filtersPass($this->app))->toBeFalse();
})->with('robos da agenda');

test('o robô com a chave local ligada mas o .env desligado não é devido na agenda', function (string $nome, string $bloco) {
    config()->set("walle.{$bloco}.ativo", false);
    ConfiguracaoRobo::definirAtivo($bloco, true, 'teste');

    expect(eventoDaAgenda($nome)->filtersPass($this->app))->toBeFalse();
})->with('robos da agenda');

test('com todos os robôs desligados nenhum job de ciclo é enfileirado', function () {
    Queue::fake();
    foreach (['contingencias', 'sentinela', 'cleantax', 'tarefas'] as $bloco) {
        config()->set("walle.{$bloco}.ativo", false);
    }

    $this->artisan('schedule:run')->assertSuccessful();

    Queue::assertNothingPushed();
});

test('os quatro robôs novos nascem desligados no config', function () {
    foreach (['contingencias', 'sentinela', 'cleantax', 'tarefas'] as $bloco) {
        expect(array_key_exists('ativo', config("walle.{$bloco}")))->toBeTrue();
    }
    expect(file_get_contents(base_path('.env.example')))
        ->toContain('WALLE_CONTINGENCIAS_ATIVO=false')
        ->toContain('WALLE_SENTINELA_ATIVO=false')
        ->toContain('WALLE_CLEANTAX_ATIVO=false')
        ->toContain('WALLE_TAREFAS_ATIVO=false');
});
