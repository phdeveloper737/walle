<?php

namespace App\Jobs;

use App\Sactus\RoboSactus;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Um ciclo do robô do Sactus (o agendador dispara de hora em hora; nunca dois ao mesmo tempo). */
class RodarCicloSactus implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public int $uniqueFor = 3600;

    public function __construct()
    {
        $this->onConnection('robos');
        $this->onQueue('sactus');
    }

    public function handle(RoboSactus $robo): void
    {
        if (! $robo->ligado() || $robo->pausa() !== null) {
            return;
        }

        $robo->rodarCiclo();
    }
}
