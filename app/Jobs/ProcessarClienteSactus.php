<?php

namespace App\Jobs;

use App\Sactus\RoboSactus;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** "Aplicar agora" do painel: o mesmo processamento do ciclo, só para um cliente. */
class ProcessarClienteSactus implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $clienteId)
    {
        $this->onConnection('robos');
        $this->onQueue('sactus-acoes');
    }

    public function uniqueId(): string
    {
        return (string) $this->clienteId;
    }

    public function handle(RoboSactus $robo): void
    {
        if ($robo->ligado()) {
            $robo->processarCliente($this->clienteId);
        }
    }
}
