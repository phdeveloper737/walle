<?php

namespace App\Jobs;

use App\Ferramentas\RoboTarefas;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Uma chamada do robô de tarefas agendadas (tipo 1 pula FALHA; tipo 2 repete só as FALHA), como o task.scheduler.bot.php do 220. */
class RodarTarefasAgendadas implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $tipoBot = 1)
    {
        $this->onConnection('robos');
        $this->onQueue('tarefas');
    }

    public function uniqueId(): string
    {
        return 'tipo-'.$this->tipoBot;
    }

    public function handle(RoboTarefas $robo): void
    {
        if (! $robo->ligado()) {
            return;
        }

        $robo->rodarPendentes($this->tipoBot);
    }
}
