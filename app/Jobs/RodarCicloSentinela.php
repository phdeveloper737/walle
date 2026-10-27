<?php

namespace App\Jobs;

use App\Sentinela\RoboSentinela;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Um ciclo único, no contrato de timeout do RodarCicloSactus e da conexão robos. */
class RodarCicloSentinela implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public int $uniqueFor = 3600;

    public function __construct()
    {
        $this->onConnection('robos');
        $this->onQueue('sentinela');
    }

    public function handle(RoboSentinela $robo): void
    {
        if (! $robo->ligado()) {
            return;
        }

        $robo->rodarCiclo();
    }
}
