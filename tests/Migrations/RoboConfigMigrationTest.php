<?php

use App\Models\RoboConfig;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

/*
 * Colunas fixadas conforme especificação da task T-0051 (tabelas novas do walle, sem equivalente no 220).
 * Ordem: nome => [tipo, aceita nulo, padrão, auto incremento, collation].
 */
test('a tabela robo_config tem as colunas esperadas', function (string $tabela, array $colunasEsperadas) {
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
    'robo_config' => ['robo_config', [
        'id' => ['bigint unsigned', false, null, true, null],
        'robo' => ['varchar(30)', false, null, false, 'utf8mb4_general_ci'],
        'parametro' => ['varchar(60)', false, null, false, 'utf8mb4_general_ci'],
        'valor' => ['text', true, null, false, 'utf8mb4_general_ci'],
        'valor_sanitizado' => ['text', true, null, false, 'utf8mb4_general_ci'],
        'criado_em' => ['timestamp', false, 'CURRENT_TIMESTAMP', false, null],
        'atualizado_em' => ['timestamp', false, 'CURRENT_TIMESTAMP', false, null],
    ]],
]);

test('a tabela robo_config tem os índices esperados', function () {
    $indexes = collect(Schema::getIndexes('robo_config'))
        ->mapWithKeys(fn (array $idx) => [$idx['name'] => [
            $idx['columns'],
            $idx['unique'],
        ]])
        ->all();

    expect($indexes)->toHaveKeys([
        'primary',
        'uq_robo_parametro',
        'idx_robo_config_criado_em',
    ]);

    expect($indexes['uq_robo_parametro'][0])->toBe(['robo', 'parametro']);
    expect($indexes['uq_robo_parametro'][1])->toBeTrue();
    expect($indexes['idx_robo_config_criado_em'][0])->toBe(['criado_em']);
});

test('model RoboConfig grava e lê uma linha', function () {
    $config = RoboConfig::create([
        'robo' => 'autorizadas',
        'parametro' => 'url_sefaz',
        'valor' => 'https://homologacao.nfe.fazenda.gov.br',
    ]);

    expect($config->id)->toBeInt();
    expect($config->robo)->toBe('autorizadas');
    expect($config->parametro)->toBe('url_sefaz');
    expect($config->valor)->toBe('https://homologacao.nfe.fazenda.gov.br');
    expect($config->valor_sanitizado)->toBe('https://homologacao.nfe.fazenda.gov.br');
    expect($config->criado_em)->not->toBeNull();
    expect($config->atualizado_em)->not->toBeNull();
});

test('tentar mudar robo lança RuntimeException', function () {
    $config = RoboConfig::create([
        'robo' => 'autorizadas',
        'parametro' => 'url_sefaz',
        'valor' => 'https://exemplo.com',
    ]);

    expect(fn () => $config->update(['robo' => 'contingencias']))->toThrow(RuntimeException::class, 'Chave de negócio imutável');
});

test('tentar mudar parametro lança RuntimeException', function () {
    $config = RoboConfig::create([
        'robo' => 'autorizadas',
        'parametro' => 'url_sefaz',
        'valor' => 'https://exemplo.com',
    ]);

    expect(fn () => $config->update(['parametro' => 'nova_url']))->toThrow(RuntimeException::class, 'Chave de negócio imutável');
});

test('sanitizar remove segredos case-insensitive', function () {
    expect(RoboConfig::sanitizar('senha123'))->toBe('***');
    expect(RoboConfig::sanitizar('SENHA'))->toBe('***');
    expect(RoboConfig::sanitizar('token_abc'))->toBe('***');
    expect(RoboConfig::sanitizar('TOKEN'))->toBe('***');
    expect(RoboConfig::sanitizar('certificado_pfx'))->toBe('***');
    expect(RoboConfig::sanitizar('CERTIFICADO'))->toBe('***');
    expect(RoboConfig::sanitizar('arquivo.pfx'))->toBe('***');
    expect(RoboConfig::sanitizar('PFX'))->toBe('***');
    expect(RoboConfig::sanitizar('chave_privada'))->toBe('***');
    expect(RoboConfig::sanitizar('CHAVE'))->toBe('***');
    expect(RoboConfig::sanitizar('secret_key'))->toBe('***');
    expect(RoboConfig::sanitizar('SECRET'))->toBe('***');
    expect(RoboConfig::sanitizar('password123'))->toBe('***');
    expect(RoboConfig::sanitizar('PASSWORD'))->toBe('***');
});

test('sanitizar mantém valores sem segredos', function () {
    expect(RoboConfig::sanitizar('url_normal'))->toBe('url_normal');
    expect(RoboConfig::sanitizar('https://exemplo.com'))->toBe('https://exemplo.com');
    expect(RoboConfig::sanitizar('valor_comum'))->toBe('valor_comum');
    expect(RoboConfig::sanitizar(''))->toBe('');
    expect(RoboConfig::sanitizar(null))->toBe('');
});
