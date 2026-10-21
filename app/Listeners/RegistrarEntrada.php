<?php

namespace App\Listeners;

use App\Models\AcessoUsuario;
use Illuminate\Auth\Events\Login;

/** Grava a entrada no a_user_acessos, como o login do sistema antigo. */
class RegistrarEntrada
{
    public function handle(Login $event): void
    {
        $acesso = AcessoUsuario::create([
            'fk_a_user' => (int) $event->user->getAuthIdentifier(),
            'data_hora_login' => now(),
            'qtd_atendimento_login' => 0,
            'qtd_atendimento_logout' => 0,
        ]);

        session(['walle.acesso_id' => $acesso->id]);
    }
}
