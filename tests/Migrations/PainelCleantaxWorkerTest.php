<?php

use App\Models\PainelCleantaxWorker;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

/*
 * Colunas fixadas a partir do 220 (SHOW CREATE TABLE / Schema::getColumns na conexão suporte),
 * na mesma ordem: nome => [tipo, aceita nulo, padrão, collation]. O teste não consulta o 220.
 * Desvio de propósito: no 220 o fk_c_cliente aceita nulo; no walle ele é obrigatório.
 */
test('a tabela painel_cleantax_worker tem as colunas, o índice único e a collation do 220', function () {
    $colunas = collect(Schema::getColumns('painel_cleantax_worker'))
        ->mapWithKeys(fn (array $coluna) => [$coluna['name'] => [
            $coluna['type'],
            $coluna['nullable'],
            $coluna['default'],
            $coluna['collation'],
        ]])
        ->all();

    expect($colunas)->toBe([
        'fk_c_cliente' => ['int', false, null, null],
        'empresa' => ['varchar(255)', true, null, 'utf8mb4_general_ci'],
        'cnpj' => ['varchar(30)', true, null, 'utf8mb4_general_ci'],
        'servidor' => ['varchar(120)', true, null, 'utf8mb4_general_ci'],
        'porta_http' => ['varchar(20)', true, null, 'utf8mb4_general_ci'],
        'banco_dados' => ['varchar(120)', true, null, 'utf8mb4_general_ci'],
        'tempo_execucao_segundos' => ['decimal(10,3)', true, null, null],
        'http_code' => ['int', true, null, null],
        'status_execucao' => ['varchar(30)', true, null, 'utf8mb4_general_ci'],
        'curl_errno' => ['int', true, null, null],
        'mensagem' => ['varchar(500)', true, null, 'utf8mb4_general_ci'],
        'url' => ['text', true, null, 'utf8mb4_general_ci'],
        'response_preview' => ['text', true, null, 'utf8mb4_general_ci'],
        'data_inicio' => ['datetime', true, null, null],
        'data_fim' => ['datetime', true, null, null],
        'data' => ['datetime', false, 'CURRENT_TIMESTAMP', null],
    ]);

    expect(Schema::getIndexes('painel_cleantax_worker'))->toBe([
        ['name' => 'fk_c_cliente', 'columns' => ['fk_c_cliente'], 'type' => 'btree', 'unique' => true, 'primary' => false],
    ]);

    expect(collect(Schema::getTables())->firstWhere('name', 'painel_cleantax_worker')['collation'])
        ->toBe('utf8mb4_general_ci');
});

test('o model grava e lê de volta a execução de um cliente', function () {
    PainelCleantaxWorker::create([
        'fk_c_cliente' => 1193,
        'empresa' => 'MERCADO TESTE LTDA',
        'tempo_execucao_segundos' => 2.5,
        'http_code' => 200,
        'status_execucao' => 'success',
        'data_inicio' => '2026-10-23 10:00:00',
        'data_fim' => '2026-10-23 10:00:02',
    ]);

    $execucao = PainelCleantaxWorker::findOrFail(1193);

    expect($execucao->empresa)->toBe('MERCADO TESTE LTDA')
        ->and($execucao->tempo_execucao_segundos)->toBe('2.500')
        ->and($execucao->http_code)->toBe(200)
        ->and($execucao->data_inicio?->toDateTimeString())->toBe('2026-10-23 10:00:00')
        ->and($execucao->data_fim?->toDateTimeString())->toBe('2026-10-23 10:00:02')
        ->and($execucao->curl_errno)->toBeNull();
});

/*
 * É o que acontece no reset de início de ciclo do 220 (UPDATE de http_code e status_execucao sem tocar em data).
 * O SET TIMESTAMP fixa o relógio da sessão do MySQL; a conexão é fechada no fim de cada teste.
 */
test('a coluna data muda sozinha para a hora do UPDATE mesmo quando o UPDATE não mexe nela', function () {
    PainelCleantaxWorker::create([
        'fk_c_cliente' => 1193,
        'http_code' => 500,
        'status_execucao' => 'error',
        'data' => '2026-10-01 08:00:00',
    ]);
    DB::statement("SET TIMESTAMP = UNIX_TIMESTAMP('2026-10-23 10:05:00')");

    PainelCleantaxWorker::query()->update(['http_code' => 200, 'status_execucao' => 'success']);

    expect(PainelCleantaxWorker::findOrFail(1193)->data->toDateTimeString())->toBe('2026-10-23 10:05:00');
});

test('gravar o painel sem o cliente (fk_c_cliente) falha com QueryException', function () {
    expect(fn () => PainelCleantaxWorker::create(['empresa' => 'MERCADO TESTE LTDA', 'status_execucao' => 'success']))
        ->toThrow(QueryException::class, 'fk_c_cliente');

    expect(PainelCleantaxWorker::count())->toBe(0);
});
