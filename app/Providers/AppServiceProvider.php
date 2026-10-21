<?php

namespace App\Providers;

use App\Auth\UsuariosHelpDesk;
use App\Support\HelpDesk\SenhaHelpDesk;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(SenhaHelpDesk::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Fora da produção: acusa lazy loading e atributo inexistente (útil com as tabelas legadas)
        Model::shouldBeStrict(! $this->app->isProduction());

        // Conexão "help": a senha só é buscada (API do Help ou reserva) quando a conexão é usada de fato
        DB::extend('help', function (array $config, string $name) {
            $config['password'] = $this->app->make(SenhaHelpDesk::class)->obter();

            return $this->app->make('db.factory')->make($config, $name);
        });

        Auth::provider('helpdesk', fn () => new UsuariosHelpDesk);
    }
}
