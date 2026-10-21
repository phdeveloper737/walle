<?php

namespace App\Jobs;

use App\Autorizadas\RoboAutorizadas;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Um ciclo do robô das Autorizadas. O agendador dispara a cada minuto; ShouldBeUnique
 * garante que nunca rodam dois ciclos ao mesmo tempo (um ciclo leva alguns minutos).
 */
class RodarCicloAutorizadas implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public int $uniqueFor = 1800;

    public function __construct()
    {
        $this->onConnection('robos');
        $this->onQueue('autorizadas');
    }

    public function handle(RoboAutorizadas $robo): void
    {
        // Conferido de novo aqui: pode ter sido desligado ou pausado depois de entrar na fila
        if (! $robo->ligado() || $robo->pausadoAte() !== null) {
            return;
        }

        $robo->rodarCiclo();
    }
}
