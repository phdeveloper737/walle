<?php

use App\Models\TaskSchedulerQuery;
use App\Models\TaskSchedulerQueryLog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

/*
 * Colunas fixadas a partir do 220 (SHOW CREATE TABLE / Schema::getColumns na conexão suporte),
 * na mesma ordem: nome => [tipo, aceita nulo, padrão, auto incremento, collation].
 * O teste não consulta o 220.
 */
test('as tabelas do Task Scheduler têm as mesmas colunas do 220', function (string $tabela, array $colunasDo220) {
    $colunas = collect(Schema::getColumns($tabela))
        ->mapWithKeys(fn (array $coluna) => [$coluna['name'] => [
            $coluna['type'],
            $coluna['nullable'],
            $coluna['default'],
            $coluna['auto_increment'],
            $coluna['collation'],
        ]])
        ->all();

    expect($colunas)->toBe($colunasDo220);
})->with([
    'task_scheduler_querys' => ['task_scheduler_querys', [
        'id' => ['int', false, null, true, null],
        'fk_a_user' => ['int', true, null, false, null],
        'descricao' => ['varchar(373)', false, 'N', false, 'utf8mb4_general_ci'],
        'data_registro' => ['datetime', true, null, false, null],
        'data_finalizado' => ['datetime', true, null, false, null],
        'query_execute' => ['varchar(3737)', true, null, false, 'utf8mb4_general_ci'],
        'finalizado' => ['char(1)', true, 'N', false, 'utf8mb4_general_ci'],
        'ativo' => ['char(1)', false, 'N', false, 'utf8mb4_general_ci'],
        'deletado' => ['char(1)', false, 'N', false, 'utf8mb4_general_ci'],
        'fk_a_user_delete' => ['int', true, null, false, null],
    ]],
    'task_scheduler_querys_log' => ['task_scheduler_querys_log', [
        'fk_task_scheduler_querys' => ['int', true, null, false, null],
        'fk_c_cliente' => ['int', true, null, false, null],
        'log_query' => ['varchar(3737)', true, null, false, 'utf8mb4_general_ci'],
        'data_time' => ['timestamp', false, 'now()', false, null],
    ]],
]);

test('o log aponta para a query que o gerou', function () {
    TaskSchedulerQuery::create(['descricao' => 'Limpar fila de NFC-e', 'query_execute' => 'DELETE FROM fila_nfce']);
    $query = TaskSchedulerQuery::create(['descricao' => 'Atualizar estoque', 'query_execute' => 'UPDATE estoque SET ok = 1']);
    TaskSchedulerQueryLog::create(['fk_task_scheduler_querys' => $query->id, 'fk_c_cliente' => 7, 'log_query' => '1 linha alterada']);

    $log = TaskSchedulerQueryLog::query()->sole();

    expect($log->taskSchedulerQuery->id)->toBe($query->id);
});

/* No 220 a FK fk_task_scheduler_querys_fk1 não tem ON DELETE (NO ACTION): o banco recusa apagar a query. */
test('apagar uma query que tem log é bloqueado pelo banco, como no 220', function () {
    $query = TaskSchedulerQuery::create(['descricao' => 'Atualizar estoque', 'query_execute' => 'UPDATE estoque SET ok = 1']);
    TaskSchedulerQueryLog::create(['fk_task_scheduler_querys' => $query->id, 'fk_c_cliente' => 7, 'log_query' => '1 linha alterada']);

    expect(fn () => $query->delete())->toThrow(QueryException::class, 'Cannot delete or update a parent row');

    $this->assertModelExists($query);
    $this->assertDatabaseHas('task_scheduler_querys_log', ['fk_task_scheduler_querys' => $query->id]);
});
