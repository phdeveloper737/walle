<?php

namespace App\Listeners;

use App\Models\AcessoUsuario;
use Illuminate\Auth\Events\Logout;

/** Grava a hora de saída no acesso aberto no login (o sistema antigo deixava sempre em branco). */
class RegistrarSaida
{
    public function handle(Logout $event): void
    {
        $acessoId = session('walle.acesso_id');

        if ($acessoId === null) {
            return;
        }

        AcessoUsuario::query()
            ->whereKey($acessoId)
            ->whereNull('data_hora_logout')
            ->update(['data_hora_logout' => now()]);
    }
}
