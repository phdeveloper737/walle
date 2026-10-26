<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase {
        migrateFreshUsing as migrateFreshUsingPadrao;
    }

    /** As conexões ficam em transação e voltam ao normal no fim de cada teste. */
    protected array $connectionsToTransact = ['mysql', 'help', 'auditoria'];

    protected function setUp(): void
    {
        parent::setUp();

        // Os testes não dependem do build do front (public/build)
        $this->withoutVite();
    }

    /**
     * Nos testes a conexão "help" aponta para um banco próprio, `<DB_DATABASE>_help` (walle_testes_help, ou
     * walle_testes_tNN_help em sessão paralela), nunca para o 192.168.10.254. Ele é separado do banco do walle
     * para a imitação do Help Desk nunca se misturar com as tabelas do walle.
     * Precisa ser feito aqui, antes do RefreshDatabase abrir as conexões.
     *
     * A conexão "auditoria" (sessão MySQL separada, usada só para a auditoria de escrita barrada que não pode ser
     * desfeita) é uma cópia exata da "mysql": o mesmo banco da sessão de teste (walle_testes ou walle_testes_tNN),
     * nunca o Help Desk. Roda em transação própria em cada teste e não deixa lixo entre eles.
     *
     * Também zera `walle.sactus.clientes_ignorados`: o AUTO_INCREMENT de c_cliente não volta com o
     * RefreshDatabase, e um id da lista faria o robô do Sactus pular o cliente criado pelo teste.
     */
    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        config(['database.connections.help' => array_merge(
            config('database.connections.mysql'),
            ['database' => config('database.connections.mysql.database').'_help'],
        )]);

        config(['database.connections.auditoria' => config('database.connections.mysql')]);

        config(['walle.sactus.clientes_ignorados' => []]);
    }

    /**
     * Migra o banco do walle e o banco de teste do Help Desk (`<db>_help`).
     * Recusa qualquer banco que não seja walle_testes ou walle_testes_tNN, antes de tocar em qualquer coisa.
     */
    protected function migrateDatabases(): void
    {
        $banco = (string) config('database.connections.mysql.database');

        $this->exigirBancoDeTeste($banco);

        DB::connection('mysql')->statement("CREATE DATABASE IF NOT EXISTS `{$banco}_help` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
        DB::purge('help');

        $this->artisan('migrate:fresh', $this->migrateFreshUsing());

        $this->artisan('migrate:fresh', [
            '--database' => 'help',
            '--path' => base_path('tests/Migrations/HelpDesk'),
            '--realpath' => true,
        ]);
    }

    /** Recusa qualquer banco que não seja walle_testes ou walle_testes_tNN, antes de qualquer SQL. */
    protected function exigirBancoDeTeste(string $banco): void
    {
        if (preg_match('/^walle_testes(_t[0-9]+)?\z/', $banco) !== 1) {
            throw new RuntimeException("Banco de testes inválido: \"{$banco}\". Use walle_testes ou walle_testes_tNN.");
        }
    }

    /** Além das migrations do walle, cria as imitações de teste do walle (tests/Migrations, sem a subpasta HelpDesk). */
    protected function migrateFreshUsing(): array
    {
        return array_merge($this->migrateFreshUsingPadrao(), [
            '--path' => [database_path('migrations'), base_path('tests/Migrations')],
            '--realpath' => true,
        ]);
    }
}
