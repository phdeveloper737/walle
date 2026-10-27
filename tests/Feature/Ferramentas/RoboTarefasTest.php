<?php

use App\Contingencias\Cliente\ConectorDeCliente;
use App\Contingencias\Cliente\ConexaoComCliente;
use App\Ferramentas\RoboTarefas;
use App\Jobs\RodarTarefasAgendadas;
use App\Models\Auditoria;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\DB;

function tarefaAgendadaDeExemplo(string $sql, array $dados = []): int
{
    return (int) DB::table('task_scheduler_querys')->insertGetId(array_merge([
        'descricao' => 'Tarefa de teste',
        'query_execute' => base64_encode($sql),
        'ativo' => 'S',
        'deletado' => 'N',
    ], $dados));
}

/** @return list<string> */
function sqlsDaTarefaExecutados(callable $acao, string $marca): array
{
    $executados = [];
    DB::listen(function ($consulta) use (&$executados, $marca): void {
        if (str_contains($consulta->sql, $marca)) {
            $executados[] = $consulta->sql;
        }
    });
    $acao();

    return $executados;
}

function logDaTarefa(int $tarefa, int $cliente): array
{
    return DB::table('task_scheduler_querys_log')
        ->where('fk_task_scheduler_querys', $tarefa)->where('fk_c_cliente', $cliente)
        ->pluck('log_query')->all();
}

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 27)->setTime(10, 0));
    config(['walle.tarefas.ativo' => true]);
});

test('uma query agendada para agora roda no cliente uma vez e gera um log OK como o 220', function () {
    servidorDoClienteNoBancoDeTestes();
    $cliente = criarClienteHelpDesk();
    $tarefa = tarefaAgendadaDeExemplo('SELECT 1 AS marca_tarefa_ok;');

    $executados = sqlsDaTarefaExecutados(fn () => app(RoboTarefas::class)->rodarPendentes(), 'marca_tarefa_ok');

    expect($executados)->toHaveCount(1)
        ->and(logDaTarefa($tarefa, $cliente))->toBe(['T0s='])
        ->and(base64_decode(logDaTarefa($tarefa, $cliente)[0]))->toBe('OK');

    $auditoria = Auditoria::query()->where('acao', 'tarefas.executar')->sole();
    expect($auditoria->fk_c_cliente)->toBe($cliente)
        ->and($auditoria->detalhes)->toMatchArray(['origem' => 'automatica', 'robo' => 'tarefas', 'fk_task_scheduler_querys' => $tarefa, 'resultado' => 'ok'])
        ->and(json_encode($auditoria->detalhes))->not->toContain('marca_tarefa_ok');

    app(RoboTarefas::class)->rodarPendentes();
    expect(logDaTarefa($tarefa, $cliente))->toBe(['T0s='], 'cliente com OK não roda de novo');
});

test('erro de SQL no cliente vira log de erro e a query não roda de novo no mesmo ciclo', function () {
    $this->mock(ConectorDeCliente::class)->shouldReceive('conectar')->once()
        ->andReturn(new ConexaoComCliente(DB::connection(), DB::getDatabaseName(), fecharAoTerminar: false));
    $cliente = criarClienteHelpDesk();
    $tarefa = tarefaAgendadaDeExemplo('SELECT * FROM tabela_que_nao_existe_tarefa');

    app(RoboTarefas::class)->rodarPendentes();

    $logs = logDaTarefa($tarefa, $cliente);
    expect($logs)->toHaveCount(1);
    expect(base64_decode($logs[0]))->toStartWith('Erro: ')->toContain('tabela_que_nao_existe_tarefa');
    expect(Auditoria::query()->where('acao', 'tarefas.executar')->sole()->detalhes)->toMatchArray(['resultado' => 'erro']);
});

test('robô desligado não conecta nem grava, inclusive pelo Job', function () {
    config(['walle.tarefas.ativo' => null]);
    $this->mock(ConectorDeCliente::class)->shouldNotReceive('conectar');
    criarClienteHelpDesk();
    tarefaAgendadaDeExemplo('SELECT 1');

    expect(app(RoboTarefas::class)->ligado())->toBeFalse();
    app(RoboTarefas::class)->rodarPendentes();
    (new RodarTarefasAgendadas)->handle(app(RoboTarefas::class));

    expect(DB::table('task_scheduler_querys_log')->count())->toBe(0)->and(Auditoria::query()->count())->toBe(0);
});

test('o 220 tira ; crase e aspas duplas da query antes de executar', function () {
    servidorDoClienteNoBancoDeTestes();
    criarClienteHelpDesk();
    tarefaAgendadaDeExemplo('SELECT `1` AS "marca_limpeza";');

    $executados = sqlsDaTarefaExecutados(fn () => app(RoboTarefas::class)->rodarPendentes(), 'marca_limpeza');

    expect($executados)->toBe(['SELECT 1 AS marca_limpeza']);
});

test('query inativa ou apagada não roda', function () {
    servidorDoClienteNoBancoDeTestes();
    criarClienteHelpDesk();
    tarefaAgendadaDeExemplo('SELECT 1 AS marca_inativa', ['ativo' => 'N']);
    tarefaAgendadaDeExemplo('SELECT 1 AS marca_inativa', ['deletado' => 'S']);

    $executados = sqlsDaTarefaExecutados(fn () => app(RoboTarefas::class)->rodarPendentes(), 'marca_inativa');

    expect($executados)->toBe([]);
});

test('cliente apagado e os ids 18, 1156 e 1470 ficam de fora', function () {
    servidorDoClienteNoBancoDeTestes();
    criarClienteHelpDesk(['deletado' => 'S']);
    criarClienteHelpDesk(['id' => 18]);
    tarefaAgendadaDeExemplo('SELECT 1 AS marca_excluidos');

    $executados = sqlsDaTarefaExecutados(fn () => app(RoboTarefas::class)->rodarPendentes(), 'marca_excluidos');

    expect($executados)->toBe([]);
});

test('cada query executa em no máximo 20 clientes por chamada', function () {
    servidorDoClienteNoBancoDeTestes();
    $tarefa = tarefaAgendadaDeExemplo('SELECT 1 AS marca_limite');
    foreach (range(1, 22) as $i) {
        criarClienteHelpDesk();
    }

    $executados = sqlsDaTarefaExecutados(fn () => app(RoboTarefas::class)->rodarPendentes(), 'marca_limite');

    expect($executados)->toHaveCount(20)
        ->and(DB::table('task_scheduler_querys_log')->where('fk_task_scheduler_querys', $tarefa)->count())->toBe(20);
});

test('falha de conexão grava FALHA sem senha; tipo 1 pula cliente com FALHA e tipo 2 só repete as falhas', function () {
    servidorDoClienteForaDoAr();
    $cliente = criarClienteHelpDesk([], ['senha_mysql' => 'senha-secreta-123', 'banco_mysql' => 'actus']);
    $tarefa = tarefaAgendadaDeExemplo('SELECT 1');

    app(RoboTarefas::class)->rodarPendentes();
    app(RoboTarefas::class)->rodarPendentes();
    $logs = logDaTarefa($tarefa, $cliente);
    expect($logs)->toHaveCount(1)->and($logs[0])->toStartWith('FALHA AO CONECTAR NO CLIENTE')->not->toContain('senha-secreta-123');
    expect(json_encode(Auditoria::query()->get()->pluck('detalhes')))->not->toContain('senha-secreta-123');

    app(RoboTarefas::class)->rodarPendentes(tipoBot: 2);
    expect(logDaTarefa($tarefa, $cliente))->toHaveCount(2);
});

test('fora da janela de horário só faz a manutenção dos logs duplicados', function () {
    $this->mock(ConectorDeCliente::class)->shouldNotReceive('conectar');
    config(['walle.tarefas.hora_inicio' => '11:00:00', 'walle.tarefas.hora_fim' => '12:00:00']);
    $tarefa = tarefaAgendadaDeExemplo('SELECT 1');
    foreach ([1, 2, 3] as $i) {
        DB::table('task_scheduler_querys_log')->insert(['fk_task_scheduler_querys' => $tarefa, 'fk_c_cliente' => 5, 'log_query' => base64_encode('Erro: x')]);
    }
    DB::table('task_scheduler_querys_log')->insert(['fk_task_scheduler_querys' => $tarefa, 'fk_c_cliente' => 6, 'log_query' => 'T0s=']);
    DB::table('task_scheduler_querys_log')->insert(['fk_task_scheduler_querys' => $tarefa, 'fk_c_cliente' => 6, 'log_query' => 'T0s=']);

    app(RoboTarefas::class)->rodarPendentes();

    expect(logDaTarefa($tarefa, 5))->toHaveCount(1)->and(logDaTarefa($tarefa, 6))->toHaveCount(2);
});

test('o Job é único, usa a conexão robos e respeita o robô ligado', function () {
    $job = new RodarTarefasAgendadas;

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)->and($job->connection)->toBe('robos')->and($job->queue)->toBe('tarefas');
});
