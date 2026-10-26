<?php

use App\Models\PainelSentinela;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

test('a tabela painel_sentinela tem as colunas, os índices e a collation do 220', function () {
    $colunas = Schema::getColumns('painel_sentinela');

    // Mesma ordem e mesmos tipos do SHOW CREATE TABLE painel_sentinela do 220
    expect(array_column($colunas, 'type', 'name'))->toBe([
        'id' => 'bigint unsigned',
        'fk_c_cliente' => 'int',
        'status' => 'char(1)',
        'http_code' => 'smallint',
        'erro' => 'varchar(500)',
        'host' => 'varchar(191)',
        'url' => 'varchar(500)',
        'hostname' => 'varchar(191)',
        'os' => 'varchar(191)',
        'kernel' => 'varchar(191)',
        'uptime' => 'varchar(60)',
        'php' => 'varchar(40)',
        'cpu_uso' => 'decimal(5,1)',
        'cpu_nucleos' => 'smallint',
        'load1' => 'decimal(6,2)',
        'load5' => 'decimal(6,2)',
        'load15' => 'decimal(6,2)',
        'mem_total_mb' => 'int',
        'mem_usado_mb' => 'int',
        'mem_pct' => 'decimal(5,1)',
        'swap_pct' => 'decimal(5,1)',
        'disco_mount' => 'varchar(120)',
        'disco_total_gb' => 'decimal(10,1)',
        'disco_usado_gb' => 'decimal(10,1)',
        'disco_pct' => 'smallint',
        'sactus_rodando' => 'char(1)',
        'sactus_mtime' => 'varchar(40)',
        'sactus_md5_ok' => 'char(1)',
        'sactus_versao' => 'varchar(60)',
        'vpn_ativo' => 'char(1)',
        'vpn_tun' => 'varchar(20)',
        'vpn_mtu' => 'int',
        'vpn_inconsistente' => 'char(1)',
        'proc_total' => 'int',
        'json' => 'json',
        'duracao_ms' => 'int',
        'data' => 'datetime',
    ]);

    expect(array_column(array_filter($colunas, fn (array $coluna) => $coluna['nullable']), 'name'))
        ->toBe(['cpu_uso', 'json']);

    $indices = collect(Schema::getIndexes('painel_sentinela'))
        ->mapWithKeys(fn (array $indice) => [$indice['name'] => ['colunas' => $indice['columns'], 'unico' => $indice['unique']]])
        ->all();

    expect($indices)->toEqual([
        'primary' => ['colunas' => ['id'], 'unico' => true],
        'uq_cliente' => ['colunas' => ['fk_c_cliente'], 'unico' => true],
        'idx_status' => ['colunas' => ['status'], 'unico' => false],
        'idx_sactus' => ['colunas' => ['sactus_rodando'], 'unico' => false],
        'idx_data' => ['colunas' => ['data'], 'unico' => false],
    ]);

    expect(collect(Schema::getTables())->firstWhere('name', 'painel_sentinela')['collation'])
        ->toBe('utf8mb4_general_ci');
});

test('o model grava e lê de volta a coleta de um cliente', function () {
    PainelSentinela::create([
        'fk_c_cliente' => 1193,
        'status' => 'S',
        'http_code' => 200,
        'hostname' => 'srv-cli-1193',
        'json' => ['hostname' => 'srv-cli-1193', 'sactus' => ['rodando' => 'S', 'versao' => '4.2.1']],
        'data' => '2026-10-23 10:15:00',
    ]);

    $coleta = PainelSentinela::where('fk_c_cliente', 1193)->sole();

    expect($coleta->status)->toBe('S')
        ->and($coleta->http_code)->toBe(200)
        ->and($coleta->hostname)->toBe('srv-cli-1193')
        ->and($coleta->json)->toEqual(['hostname' => 'srv-cli-1193', 'sactus' => ['rodando' => 'S', 'versao' => '4.2.1']])
        ->and($coleta->data)->toBeInstanceOf(Carbon::class)
        ->and($coleta->data->toDateTimeString())->toBe('2026-10-23 10:15:00')
        ->and($coleta->sactus_rodando)->toBe('X')
        ->and($coleta->erro)->toBe('')
        ->and($coleta->cpu_uso)->toBeNull();
});

test('gravar uma coleta sem o cliente (fk_c_cliente) falha com QueryException', function () {
    expect(fn () => PainelSentinela::create(['status' => 'S', 'data' => '2026-10-23 10:15:00']))
        ->toThrow(QueryException::class, 'fk_c_cliente');

    expect(PainelSentinela::count())->toBe(0);
});
