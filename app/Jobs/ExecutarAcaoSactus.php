<?php

namespace App\Jobs;

use App\Models\AcaoSactus;
use App\Sactus\AcoesSactus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Ação manual pedida por um admin (yum update, instalar acl) no servidor de um cliente. */
class ExecutarAcaoSactus implements ShouldQueue
{
    use Queueable;

    public int $timeout = 2400;

    public int $tries = 1;

    public function __construct(public readonly int $acaoId)
    {
        $this->onConnection('robos');
        $this->onQueue('sactus-acoes');
    }

    public function handle(AcoesSactus $acoes): void
    {
        $pedido = AcaoSactus::find($this->acaoId);

        if ($pedido === null) {
            return;
        }

        // Desligado na hora de rodar (alguém desligou depois do pedido): não encosta no servidor
        if (! config('walle.sactus.ativo')) {
            $pedido->forceFill(['status' => 'ERRO', 'erro' => 'O Sactus do walle está desligado.', 'processado_em' => now()])->save();

            return;
        }

        $acoes->executar($pedido);
    }
}
