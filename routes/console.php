<?php

use App\Autorizadas\RoboAutorizadas;
use App\Jobs\RodarCicloAutorizadas;
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
