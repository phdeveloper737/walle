<?php

namespace Tests;

use App\Support\HelpDesk\SenhaHelpDesk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase {
        migrateFreshUsing as migrateFreshUsingPadrao;
    }

    /** As duas conexões ficam em transação e voltam ao normal no fim de cada teste. */
    protected array $connectionsToTransact = ['mysql', 'help'];

    protected function setUp(): void
    {
        parent::setUp();

        // Os testes não dependem do build do front (public/build)
        $this->withoutVite();
    }

    /**
     * Nos testes a conexão "help" aponta para o banco de testes (walle_testes), nunca para o 192.168.10.254.
     * Precisa ser feito aqui, antes do RefreshDatabase abrir as conexões.
     */
    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        config(['database.connections.help' => config('database.connections.mysql')]);

        $this->app->instance(SenhaHelpDesk::class, new class extends SenhaHelpDesk
        {
            public function obter(): string
            {
                return (string) config('database.connections.mysql.password');
            }
        });
    }

    /** Além das migrations do walle, cria a imitação do a_user do Help Desk (tests/Migrations). */
    protected function migrateFreshUsing(): array
    {
        return array_merge($this->migrateFreshUsingPadrao(), [
            '--path' => [database_path('migrations'), base_path('tests/Migrations')],
            '--realpath' => true,
        ]);
    }
}
