<?php

use App\Models\RoboConfigHistorico;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

/*
 * Colunas fixadas conforme especificação da task T-0051 (tabelas novas do walle, sem equivalente no 220).
 * Ordem: nome => [tipo, aceita nulo, padrão, auto incremento, collation].
 */
test('a tabela robo_config_historico tem as colunas esperadas', function (string $tabela, array $colunasEsperadas) {
    $colunas = collect(Schema::getColumns($tabela))
        ->mapWithKeys(fn (array $coluna) => [$coluna['name'] => [
            $coluna['type'],
            $coluna['nullable'],
            $coluna['default'],
            $coluna['auto_increment'],
            $coluna['collation'],
        ]])
        ->all();

    expect($colunas)->toBe($colunasEsperadas);
})->with([
    'robo_config_historico' => ['robo_config_historico', [
        'id' => ['bigint unsigned', false, null, true, null],
        'robo' => ['varchar(30)', false, null, false, 'utf8mb4_general_ci'],
        'parametro' => ['varchar(60)', false, null, false, 'utf8mb4_general_ci'],
        'acao' => ['varchar(10)', false, null, false, 'utf8mb4_general_ci'],
        'valor_anterior_sanitizado' => ['text', true, null, false, 'utf8mb4_general_ci'],
        'valor_novo_sanitizado' => ['text', true, null, false, 'utf8mb4_general_ci'],
        'ator' => ['varchar(100)', false, null, false, 'utf8mb4_general_ci'],
        'ip' => ['varchar(45)', true, null, false, 'utf8mb4_general_ci'],
        'criado_em' => ['timestamp', false, 'CURRENT_TIMESTAMP', false, null],
    ]],
]);

test('a tabela robo_config_historico tem os índices esperados', function () {
    $indexes = collect(Schema::getIndexes('robo_config_historico'))
        ->mapWithKeys(fn (array $idx) => [$idx['name'] => [
            $idx['columns'],
            $idx['unique'],
        ]])
        ->all();

    expect($indexes)->toHaveKeys([
        'primary',
        'idx_robo_parametro_criado_em',
        'idx_ator_criado_em',
        'idx_robo_config_historico_criado_em',
    ]);

    expect($indexes['idx_robo_parametro_criado_em'][0])->toBe(['robo', 'parametro', 'criado_em']);
    expect($indexes['idx_ator_criado_em'][0])->toBe(['ator', 'criado_em']);
    expect($indexes['idx_robo_config_historico_criado_em'][0])->toBe(['criado_em']);
});

test('model RoboConfigHistorico grava uma linha', function () {
    $historico = RoboConfigHistorico::create([
        'robo' => 'autorizadas',
        'parametro' => 'url_sefaz',
        'acao' => 'alterar',
        'valor_anterior_sanitizado' => 'https://antiga.com',
        'valor_novo_sanitizado' => 'https://nova.com',
        'ator' => 'admin_teste',
        'ip' => '192.168.1.1',
    ]);

    expect($historico->id)->toBeInt();
    expect($historico->robo)->toBe('autorizadas');
    expect($historico->parametro)->toBe('url_sefaz');
    expect($historico->acao)->toBe('alterar');
    expect($historico->valor_anterior_sanitizado)->toBe('https://antiga.com');
    expect($historico->valor_novo_sanitizado)->toBe('https://nova.com');
    expect($historico->ator)->toBe('admin_teste');
    expect($historico->ip)->toBe('192.168.1.1');
    expect($historico->criado_em)->not->toBeNull();
});

test('atualizar histórico lança RuntimeException (histórico imutável)', function () {
    $historico = RoboConfigHistorico::create([
        'robo' => 'autorizadas',
        'parametro' => 'url_sefaz',
        'acao' => 'alterar',
        'valor_anterior_sanitizado' => 'a',
        'valor_novo_sanitizado' => 'b',
        'ator' => 'admin',
    ]);

    expect(fn () => $historico->update(['acao' => 'ativar']))->toThrow(RuntimeException::class, 'Histórico imutável');
});

test('escrita em massa pelo builder lança RuntimeException (histórico imutável)', function (Closure $operacao) {
    RoboConfigHistorico::create([
        'robo' => 'autorizadas',
        'parametro' => 'url_sefaz',
        'acao' => 'alterar',
        'valor_anterior_sanitizado' => 'a',
        'valor_novo_sanitizado' => 'b',
        'ator' => 'admin',
    ]);

    expect($operacao)->toThrow(RuntimeException::class, 'Histórico imutável');
    expect(RoboConfigHistorico::query()->count())->toBe(1);
    expect(RoboConfigHistorico::query()->value('acao'))->toBe('alterar');
})->with([
    'update' => [fn () => RoboConfigHistorico::query()->update(['acao' => 'ativar'])],
    'delete' => [fn () => RoboConfigHistorico::query()->delete()],
    'forceDelete' => [fn () => RoboConfigHistorico::query()->forceDelete()],
    'increment' => [fn () => RoboConfigHistorico::query()->increment('id')],
    'decrement' => [fn () => RoboConfigHistorico::query()->decrement('id')],
    'where()->update' => [fn () => RoboConfigHistorico::where('robo', 'autorizadas')->update(['acao' => 'ativar'])],
]);

test('apagar histórico lança RuntimeException (histórico imutável)', function () {
    $historico = RoboConfigHistorico::create([
        'robo' => 'autorizadas',
        'parametro' => 'url_sefaz',
        'acao' => 'alterar',
        'valor_anterior_sanitizado' => 'a',
        'valor_novo_sanitizado' => 'b',
        'ator' => 'admin',
    ]);

    expect(fn () => $historico->delete())->toThrow(RuntimeException::class, 'Histórico imutável');
});
