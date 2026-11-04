<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Esqueleto vazio, sem registro no bootstrap/providers.php. Fica à espera da ConfiguracaoRobo e do ContratoRobo
 * (task 5.2), que dependem de arquivos fora do domínio do walle-infra.
 */
class RoboServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }
}
