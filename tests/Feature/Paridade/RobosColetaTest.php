<?php

use App\CleanTax\RoboCleanTax;
use App\Contingencias\Cliente\ConectorDeCliente;
use App\Contingencias\Cliente\ConexaoComCliente;
use App\Contingencias\Cliente\FalhaConexaoCliente;
use App\Contingencias\RoboContingencias;
use App\Ferramentas\RoboTarefas;
use App\Sentinela\RoboSentinela;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function (): void {
    $this->travelTo(now()->setDate(2026, 10, 27)->setTime(10, 0));
    Http::preventStrayRequests();
    Sleep::fake();
});

test('Sentinela grava linha exata e estado de erro idênticos ao 220', function (): void {
    config(['walle.sentinela.ativo' => true]);
    $clienteOk = criarClienteHelpDesk([], ['ip_externo_servidor' => '10.8.0.3']);
    $clienteErro = criarClienteHelpDesk([], ['ip_externo_servidor' => '10.8.0.21']);
    $amostra = require base_path('tests/Fixtures/legado/robos/sentinela-ok.php');

    Http::fake([
        'http://10.8.0.3/sentinela.php*' => Http::response($amostra['json'], 200),
        'http://10.8.0.21/sentinela.php*' => fn () => throw new ConnectionException('cURL error 28: Connection timed out after 8001 milliseconds'),
    ]);

    app(RoboSentinela::class)->rodarCiclo();

    $linhaOk = DB::table('painel_sentinela')->where('fk_c_cliente', $clienteOk)->first();
    $colunasOk = collect((array) $linhaOk)->except(['id', 'fk_c_cliente', 'data', 'json', 'duracao_ms'])->toArray();
    expect($colunasOk)->toEqual(collect($amostra['linha_sucesso'])->except(['json', 'duracao_ms'])->toArray())
        ->and(json_decode($linhaOk->json, true))->toEqual(json_decode($amostra['json'], true))
        ->and((int) $linhaOk->duracao_ms)->toBeGreaterThan(0);

    $linhaErro = DB::table('painel_sentinela')->where('fk_c_cliente', $clienteErro)->first();
    $colunasErro = collect((array) $linhaErro)->except(['id', 'fk_c_cliente', 'data', 'duracao_ms'])->toArray();
    expect($colunasErro)->toEqual(collect($amostra['linha_erro'])->except(['duracao_ms'])->toArray());
});

test('CleanTax grava sucesso e erro exatos conforme amostras do 220', function (): void {
    config(['walle.cleantax.ativo' => true]);
    $amostras = require base_path('tests/Fixtures/legado/robos/cleantax.php');

    Http::fake([
        RoboCleanTax::API_ENDPOINT => Http::response([
            'status' => true,
            'message' => [$amostras['cliente_sucesso'], $amostras['cliente_erro']],
        ], 200),
        'http://10.8.0.11*' => Http::response($amostras['resposta_sucesso'], 200),
        'http://10.8.0.21*' => fn () => throw new ConnectionException('cURL error 28: Connection timed out'),
    ]);

    app(RoboCleanTax::class)->rodarCiclo();

    $linhaSucesso = DB::table('painel_cleantax_worker')->where('fk_c_cliente', 10)->first();
    $colunasSucesso = collect((array) $linhaSucesso)->except(['data', 'tempo_execucao_segundos'])->toArray();
    expect($colunasSucesso)->toEqual(collect($amostras['linha_sucesso'])->except(['tempo_execucao_segundos'])->toArray());

    $linhaErro = DB::table('painel_cleantax_worker')->where('fk_c_cliente', 26)->first();
    $colunasErro = collect((array) $linhaErro)->except(['data', 'tempo_execucao_segundos'])->toArray();
    expect($colunasErro)->toEqual(collect($amostras['linha_erro'])->except(['tempo_execucao_segundos'])->toArray());
});

test('Contingência grava totais exatos iguais à amostra do 220', function (): void {
    config(['walle.contingencias.ativo' => true]);
    $amostra = require base_path('tests/Fixtures/legado/robos/contingencia.php');
    $cliente = criarClienteHelpDesk(['consultoria' => 'S']);
    foreach ($amostra['sactus'] as $dados) {
        criarContingenciaSactus($dados);
    }
    servidorDoClienteNoBancoDeTestes();

    app(RoboContingencias::class)->rodarCiclo();

    $linha = DB::table('painel_contingency_crawler')->where('fk_c_cliente', $cliente)->first();
    $colunas = collect((array) $linha)->except(['data', 'fk_c_cliente'])->toArray();
    expect($colunas)->toEqual(collect($amostra['linha_sucesso'])->except(['data'])->toArray());
});

test('Task Scheduler grava T0s= como 220 para execução bem sucedida e log de erro exato', function (): void {
    config(['walle.tarefas.ativo' => true]);
    $amostra = require base_path('tests/Fixtures/legado/robos/task-scheduler.php');
    servidorDoClienteNoBancoDeTestes();
    $clienteOk = criarClienteHelpDesk();
    $clienteErro = criarClienteHelpDesk();
    $tarefa = DB::table('task_scheduler_querys')->insertGetId($amostra['tarefa']);

    $mockConector = $this->mock(ConectorDeCliente::class);
    $mockConector->shouldReceive('conectar')
        ->with($clienteOk, Mockery::type('array'))
        ->once()
        ->andReturn(new ConexaoComCliente(DB::connection(), DB::getDatabaseName(), false));

    $mockConector->shouldReceive('conectar')
        ->with($clienteErro, Mockery::type('array'))
        ->once()
        ->andThrow(new FalhaConexaoCliente('ERRO_MYSQL', 'falha ao conectar', 'SQL'));

    app(RoboTarefas::class)->rodarPendentes();

    expect(DB::table('task_scheduler_querys_log')->where(['fk_task_scheduler_querys' => $tarefa, 'fk_c_cliente' => $clienteOk])->value('log_query'))
        ->toBe($amostra['log_ok']);

    expect(DB::table('task_scheduler_querys_log')->where(['fk_task_scheduler_querys' => $tarefa, 'fk_c_cliente' => $clienteErro])->value('log_query'))
        ->toBe('FALHA AO CONECTAR NO CLIENTE: falha ao conectar');
});

test('robôs desligados não fazem chamadas, não alteram tabelas e não criam registros', function (): void {
    config(['walle.sentinela.ativo' => false, 'walle.cleantax.ativo' => false, 'walle.contingencias.ativo' => false, 'walle.tarefas.ativo' => false]);
    $cliente = criarClienteHelpDesk([], ['ip_externo_servidor' => '10.8.0.3']);
    $amostra = require base_path('tests/Fixtures/legado/robos/sentinela-ok.php');
    Http::fake(['http://10.8.0.3/sentinela.php*' => Http::response($amostra['json'], 200)]);

    app(RoboSentinela::class)->rodarCiclo();
    app(RoboCleanTax::class)->rodarCiclo();
    app(RoboContingencias::class)->rodarCiclo();
    app(RoboTarefas::class)->rodarPendentes();

    Http::assertNothingSent();
    expect(DB::table('painel_sentinela')->count())->toBe(0)
        ->and(DB::table('painel_cleantax_worker')->count())->toBe(0)
        ->and(DB::table('painel_contingency_crawler')->count())->toBe(0)
        ->and(DB::table('task_scheduler_querys_log')->count())->toBe(0);
});
