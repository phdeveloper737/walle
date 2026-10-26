<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * A origem do importador (conexão "suporte") aponta para o banco `<DB_DATABASE>_origem` (walle_testes_origem, ou
 * walle_testes_tNN_origem em sessão paralela), nunca para o testes220.
 * As tabelas da origem são criadas pelas próprias migrations do walle e apagadas no fim de cada teste.
 */

/** Tabelas criadas na origem, na ordem de apagar (filho antes do pai). */
const TABELAS_NA_ORIGEM = ['task_scheduler_querys_log', 'task_scheduler_querys', 'painel_sentinela'];

/** Um banco de origem por banco de testes: sessões paralelas não apagam as tabelas umas das outras. */
function bancoDaOrigem(): string
{
    return config('database.connections.mysql.database').'_origem';
}

beforeEach(function () {
    // Cria a origem se faltar (CI, volume novo). Conexão avulsa e sem banco: o CREATE DATABASE faz commit
    // implícito e não pode rodar na conexão mysql, que está na transação do RefreshDatabase.
    DB::build(array_merge(config('database.connections.mysql'), ['database' => null]))
        ->statement('CREATE DATABASE IF NOT EXISTS `'.bancoDaOrigem().'` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');

    config(['database.connections.suporte' => array_merge(config('database.connections.mysql'), ['database' => bancoDaOrigem()])]);
    DB::purge('suporte');
});

afterEach(function () {
    $origem = DB::connection('suporte');

    // Trava de segurança: só apaga se a origem for mesmo o banco de testes
    if ($origem->getDatabaseName() !== bancoDaOrigem() || $origem->getConfig('host') !== config('database.connections.mysql.host')) {
        throw new RuntimeException('A conexão suporte não aponta para o banco de origem dos testes: nada foi apagado.');
    }

    foreach (TABELAS_NA_ORIGEM as $tabela) {
        Schema::connection('suporte')->dropIfExists($tabela);
    }

    DB::purge('suporte');
});

/** Cria a tabela na origem rodando o up() da migration do walle na conexão "suporte". */
function criarTabelaNaOrigem(string $arquivoDaMigration): void
{
    $migration = require database_path('migrations/'.$arquivoDaMigration);
    $padrao = DB::getDefaultConnection();

    DB::setDefaultConnection('suporte');

    try {
        $migration->up();
    } finally {
        DB::setDefaultConnection($padrao);
    }
}

/**
 * Todas as linhas da tabela na conexão, em ordem por todas as colunas (dá para comparar origem e destino).
 *
 * @return list<array<string, mixed>>
 */
function linhasDaTabela(string $conexao, string $tabela): array
{
    $consulta = DB::connection($conexao)->table($tabela);

    foreach (Schema::connection($conexao)->getColumnListing($tabela) as $coluna) {
        $consulta->orderBy($coluna);
    }

    return $consulta->get()->map(fn (object $linha): array => (array) $linha)->all();
}

test('importar só painel_sentinela copia as linhas da origem e rodar de novo não duplica', function () {
    criarTabelaNaOrigem('2026_10_23_110001_create_painel_sentinela_table.php');
    DB::connection('suporte')->table('painel_sentinela')->insert([
        ['id' => 1, 'fk_c_cliente' => 1193, 'status' => 'S', 'http_code' => 200, 'hostname' => 'srv-1193', 'cpu_uso' => 12.5, 'json' => '{"sactus": {"rodando": "S"}}', 'data' => '2026-10-23 10:15:00'],
        ['id' => 2, 'fk_c_cliente' => 1194, 'status' => 'N', 'http_code' => 0, 'hostname' => '', 'cpu_uso' => null, 'json' => null, 'data' => '2026-10-23 10:16:00'],
        ['id' => 7, 'fk_c_cliente' => 1201, 'status' => 'S', 'http_code' => 200, 'hostname' => 'srv-1201', 'cpu_uso' => 80.0, 'json' => '{"vpn": {"ativo": "S"}}', 'data' => '2026-10-23 10:17:00'],
    ]);
    // Linha antiga no walle, que não existe mais na origem: a importação substitui tudo
    DB::table('painel_sentinela')->insert(['id' => 99, 'fk_c_cliente' => 9999, 'data' => '2026-01-01 00:00:00']);

    $this->artisan('walle:importar-suporte', ['tabelas' => ['painel_sentinela']])->assertSuccessful();

    $origem = linhasDaTabela('suporte', 'painel_sentinela');
    expect($origem)->toHaveCount(3)
        ->and(linhasDaTabela('mysql', 'painel_sentinela'))->toBe($origem);

    $this->artisan('walle:importar-suporte', ['tabelas' => ['painel_sentinela']])->assertSuccessful();

    expect(linhasDaTabela('mysql', 'painel_sentinela'))->toBe($origem);
});

test('pedir uma tabela fora de TABELAS encerra com erro e não importa nada', function () {
    criarTabelaNaOrigem('2026_10_23_110001_create_painel_sentinela_table.php');
    DB::connection('suporte')->table('painel_sentinela')->insert(['id' => 1, 'fk_c_cliente' => 1193, 'data' => '2026-10-23 10:15:00']);
    DB::table('painel_sentinela')->insert(['id' => 99, 'fk_c_cliente' => 9999, 'data' => '2026-01-01 00:00:00']);

    $this->artisan('walle:importar-suporte', ['tabelas' => ['painel_sentinela', 'c_cliente']])
        ->expectsOutput('Tabela fora da lista do walle: c_cliente')
        ->assertFailed();

    expect(DB::table('painel_sentinela')->pluck('fk_c_cliente')->all())->toBe([9999]);
});

test('task_scheduler_querys_log, sem chave nenhuma, é copiado inteiro em vários lotes, com as linhas repetidas', function () {
    criarTabelaNaOrigem('2026_10_23_150001_create_task_scheduler_querys_table.php');
    criarTabelaNaOrigem('2026_10_23_150002_create_task_scheduler_querys_log_table.php');
    DB::connection('suporte')->table('task_scheduler_querys')->insert(['id' => 3, 'descricao' => 'Limpar fila', 'query_execute' => 'DELETE FROM fila']);

    // 250 linhas na mesma query (3 lotes de 100): 200 diferentes e 50 repetidas, como acontece no 220
    $logs = [];
    for ($i = 1; $i <= 200; $i++) {
        $logs[] = ['fk_task_scheduler_querys' => 3, 'fk_c_cliente' => 1000 + ($i % 40), 'log_query' => "OK {$i}", 'data_time' => '2026-10-23 10:00:00'];
    }
    DB::connection('suporte')->table('task_scheduler_querys_log')->insert(array_merge($logs, array_slice($logs, 0, 50)));

    $this->artisan('walle:importar-suporte', ['tabelas' => ['task_scheduler_querys', 'task_scheduler_querys_log'], '--lote' => 100])
        ->assertSuccessful();

    $origem = linhasDaTabela('suporte', 'task_scheduler_querys_log');
    expect($origem)->toHaveCount(250)
        ->and(linhasDaTabela('mysql', 'task_scheduler_querys_log'))->toBe($origem)
        ->and(linhasDaTabela('mysql', 'task_scheduler_querys'))->toBe(linhasDaTabela('suporte', 'task_scheduler_querys'));
});
