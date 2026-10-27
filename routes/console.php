<?php

use App\Autorizadas\RoboAutorizadas;
use App\CleanTax\RoboCleanTax;
use App\Contingencias\RoboContingencias;
use App\Ferramentas\RoboTarefas;
use App\Jobs\RodarCicloAutorizadas;
use App\Jobs\RodarCicloCleanTax;
use App\Jobs\RodarCicloContingencias;
use App\Jobs\RodarCicloSactus;
use App\Jobs\RodarCicloSentinela;
use App\Jobs\RodarTarefasAgendadas;
use App\Sactus\RoboSactus;
use App\Sentinela\RoboSentinela;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Batida do agendador: a tela "Saúde do sistema" confere se o walle-scheduler está vivo.
// Guarda o timestamp (número): o cache do Laravel 13 não desserializa objetos (cache.serializable_classes).
Schedule::call(fn () => Cache::put('walle:agendador:batida', now()->getTimestamp(), now()->addMinutes(10)))
    ->everyMinute()
    ->name('walle:batida-do-agendador');

// Robô das Autorizadas: só com WALLE_AUTORIZADAS_ATIVO=true e sem pausa (o job é único: um ciclo por vez)
Schedule::job(new RodarCicloAutorizadas)
    ->everyMinute()
    ->name('walle:robo-autorizadas')
    ->when(fn (RoboAutorizadas $robo): bool => $robo->ligado() && $robo->pausadoAte() === null);

// Robô do Sactus: de hora em hora, só com WALLE_SACTUS_ATIVO=true e sem pausa (o job é único)
Schedule::job(new RodarCicloSactus)
    ->hourly()
    ->name('walle:robo-sactus')
    ->when(fn (RoboSactus $robo): bool => $robo->ligado() && $robo->pausa() === null);

// Robô das Contingências: a cada 10 s (como o daemon do 220), só com WALLE_CONTINGENCIAS_ATIVO=true (o job é único)
Schedule::job(new RodarCicloContingencias)
    ->everyTenSeconds()
    ->name('walle:robo-contingencias')
    ->when(fn (RoboContingencias $robo): bool => $robo->ligado());

// Robô do Sentinela: a cada 10 minutos (600 s no 220), só com WALLE_SENTINELA_ATIVO=true (o job é único)
Schedule::job(new RodarCicloSentinela)
    ->everyTenMinutes()
    ->name('walle:robo-sentinela')
    ->when(fn (RoboSentinela $robo): bool => $robo->ligado());

// Robô do CleanTax: a cada 30 s, só com WALLE_CLEANTAX_ATIVO=true (o job é único)
Schedule::job(new RodarCicloCleanTax)
    ->everyThirtySeconds()
    ->name('walle:robo-cleantax')
    ->when(fn (RoboCleanTax $robo): bool => $robo->ligado());

// Robô das tarefas agendadas: todo minuto, só com WALLE_TAREFAS_ATIVO=true (o job é único)
Schedule::job(new RodarTarefasAgendadas)
    ->everyMinute()
    ->name('walle:robo-tarefas')
    ->when(fn (RoboTarefas $robo): bool => $robo->ligado());
