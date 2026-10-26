<?php

namespace App\Providers;

use App\Auth\UsuariosHelpDesk;
use App\Models\Usuario;
use App\Sactus\Ssh\ConectorPhpseclib;
use App\Sactus\Ssh\ConectorSsh;
use App\Support\HelpDesk\TravaHelpDeskProducao;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TravaHelpDeskProducao::class);

        // SSH de verdade nos servidores dos clientes; os testes trocam por um servidor falso
        $this->app->bind(ConectorSsh::class, ConectorPhpseclib::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Fora da produção: acusa lazy loading e atributo inexistente (útil com as tabelas legadas)
        Model::shouldBeStrict(! $this->app->isProduction());

        // Help Desk de produção é só consulta: toda conexão (inclusive as recriadas depois de um DB::purge
        // e as dinâmicas do DB::build) recebe a trava que barra escrita antes de abrir o PDO
        $trava = $this->app->make(TravaHelpDeskProducao::class);
        Event::listen(ConnectionEstablished::class, fn (ConnectionEstablished $evento) => $trava->proteger($evento->connection));
        foreach (DB::getConnections() as $conexao) {
            $trava->proteger($conexao);
        }

        Auth::provider('helpdesk', fn () => new UsuariosHelpDesk);

        // Admin: id do a_user na lista WALLE_ADMINS do .env (ações fora do walle e segredos)
        Gate::define('admin', fn (Usuario $usuario): bool => in_array($usuario->id, config('walle.admins'), true));
    }
}
