<?php

use App\Models\PainelMercadologicalWorker;
use App\Models\PainelMercadologicalWorkerLog;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

test('as duas tabelas da classificação mercadológica têm as mesmas colunas do 220', function () {
    $colunas = fn (string $tabela): array => collect(Schema::getColumns($tabela))
        ->mapWithKeys(fn (array $coluna): array => [$coluna['name'] => $coluna['type'].($coluna['nullable'] ? ' null' : '')])
        ->all();

    expect($colunas('painel_mercadological_worker'))->toBe([
        'fk_c_cliente' => 'int null',
        'total' => 'int null',
        'data' => 'datetime',
        'lidos' => 'int',
        'fora_base' => 'int',
        'conferidos' => 'int',
        'corretos' => 'int',
        'divergentes' => 'int',
        'atualizados' => 'int',
        'restantes' => 'int',
        'lotes' => 'smallint',
        'classificacao' => 'int',
        'erros' => 'smallint',
        'segundos' => 'decimal(8,2)',
        'parcial' => 'char(1)',
        'status' => 'varchar(12)',
        'etapa_final' => 'varchar(40)',
        'ultimo_erro' => 'varchar(255)',
        'atualizados_acumulado' => 'bigint',
    ]);

    expect($colunas('painel_mercadological_worker_log'))->toBe([
        'id' => 'int',
        'fk_c_cliente' => 'int',
        'tipo' => 'varchar(20)',
        'etapa' => 'varchar(60)',
        'mensagem' => 'mediumtext',
        'criado_em' => 'datetime',
    ]);
});

test('o histórico de um cliente volta pela relação do painel', function () {
    $painel = PainelMercadologicalWorker::create(['fk_c_cliente' => 10, 'total' => 500]);
    PainelMercadologicalWorkerLog::create([
        'fk_c_cliente' => 10, 'tipo' => 'INFO', 'etapa' => 'CONEXAO_300', 'mensagem' => 'Conectado ao servidor do cliente',
    ]);
    PainelMercadologicalWorkerLog::create([
        'fk_c_cliente' => 10, 'tipo' => 'ERROR', 'etapa' => 'REPLACE_GRUPO', 'mensagem' => 'Falha ao gravar o grupo',
    ]);

    $historico = $painel->fresh()->logs;

    expect($historico->pluck('mensagem')->all())
        ->toEqualCanonicalizing(['Conectado ao servidor do cliente', 'Falha ao gravar o grupo']);
});

test('o log de um cliente que não está no painel não aparece no histórico de outro cliente', function () {
    $painel = PainelMercadologicalWorker::create(['fk_c_cliente' => 10]);
    PainelMercadologicalWorkerLog::create([
        'fk_c_cliente' => 10, 'tipo' => 'SUCCESS', 'etapa' => 'FIM', 'mensagem' => 'Log do cliente 10',
    ]);
    PainelMercadologicalWorkerLog::create([
        'fk_c_cliente' => 99, 'tipo' => 'ERROR', 'etapa' => 'CONEXAO_300', 'mensagem' => 'Log do cliente 99, sem painel',
    ]);

    $historico = $painel->logs;

    expect($historico->pluck('mensagem')->all())->toBe(['Log do cliente 10']);
});
