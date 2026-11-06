<?php

namespace Tests;

use App\Support\HelpDesk\TravaHelpDeskProducao;
use Illuminate\Database\MySqlConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Tests\Support\PreparadorDeBancosDeTeste;

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

        $this->apontarLegado220ParaOBancoDeTeste();

        config(['walle.sactus.clientes_ignorados' => []]);
    }

    /**
     * Nos testes a conexão "legado220" (banco "suporte" do 220 de produção) usa o banco de testes do walle
     * (walle_testes ou walle_testes_tNN), onde ficam as tabelas contingencias_versatus_venda(+itens), nunca o
     * 192.168.10.220. Recusa a troca se o banco principal estiver num host de produção.
     *
     * Ela usa a mesma sessão MySQL da "mysql" (o PDO é o dela, aberto só na primeira consulta): as fixtures
     * gravadas pela "mysql" dentro da transação do teste são lidas pela legado220, que segue somente leitura
     * (TravaHelpDeskProducao, pelo nome) e não entra em $connectionsToTransact.
     */
    protected function apontarLegado220ParaOBancoDeTeste(): void
    {
        $configuracao = config('database.connections.mysql');

        if (! is_array($configuracao) || TravaHelpDeskProducao::hostDeProducao($configuracao) !== null) {
            throw new \RuntimeException('Nos testes, a conexão legado220 não pode apontar para um host de produção.');
        }

        config(['database.connections.'.TravaHelpDeskProducao::CONEXAO_LEGADO_220 => $configuracao]);

        $bancos = $this->app->make('db');
        $bancos->extend(TravaHelpDeskProducao::CONEXAO_LEGADO_220, fn (array $config, string $nome): MySqlConnection => new MySqlConnection(
            fn () => $bancos->connection('mysql')->getPdo(),
            (string) $config['database'],
            (string) ($config['prefix'] ?? ''),
            [...$config, 'name' => $nome],
        ));
    }

    /**
     * Migra o banco do walle e o banco de teste do Help Desk (`<db>_help`).
     * Recusa qualquer banco que não seja walle_testes ou walle_testes_tNN, antes de tocar em qualquer coisa.
     */
    protected function migrateDatabases(): void
    {
        $configuracao = config('database.connections.mysql');

        if (! is_array($configuracao)) {
            throw new \RuntimeException('A conexão mysql não está configurada.');
        }

        (new PreparadorDeBancosDeTeste(app('db')))->preparar($configuracao);

        DB::purge('mysql');
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
        PreparadorDeBancosDeTeste::exigirNomeSeguro($banco);
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
