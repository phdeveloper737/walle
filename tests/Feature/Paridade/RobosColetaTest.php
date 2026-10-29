<?php

use App\CleanTax\RoboCleanTax;
use App\Contingencias\RoboContingencias;
use App\Ferramentas\RoboTarefas;
use App\Sentinela\RoboSentinela;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function (): void {
    $this->travelTo(now()->setDate(2026, 10, 27)->setTime(10, 0));
    Http::preventStrayRequests();
    Sleep::fake();
});

test('Sentinela grava linha igual à amostra capturada do 220', function (): void {
    config(['walle.sentinela.ativo' => true]);
    $cliente = criarClienteHelpDesk([], ['ip_externo_servidor' => '10.8.0.5']);
    $amostra = require base_path('tests/Fixtures/legado/robos/sentinela-ok.php');
    Http::fake(['http://10.8.0.5/sentinela.php*' => Http::response($amostra['json'], 200)]);

    app(RoboSentinela::class)->rodarCiclo();

    $linha = DB::table('painel_sentinela')->where('fk_c_cliente', $cliente)->sole();
    expect((array) $linha)->toMatchArray($amostra['linha']);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://10.8.0.5/sentinela.php?json=S');
});

test('CleanTax grava sucesso e erro conforme amostras do 220', function (): void {
    config(['walle.cleantax.ativo' => true]);
    $amostras = require base_path('tests/Fixtures/legado/robos/cleantax.php');
    Http::fake([
        RoboCleanTax::API_ENDPOINT => Http::response(['status' => true, 'message' => [$amostras['cliente']]], 200),
        'http://10.8.0.11*' => Http::response($amostras['resposta'], 200),
    ]);

    app(RoboCleanTax::class)->rodarCiclo();

    expect(DB::table('painel_cleantax_worker')->where('fk_c_cliente', $amostras['cliente']['fk_c_cliente'])->sole())
        ->toMatchObject($amostras['linha']);
});

test('Contingência grava totais iguais à amostra do 220', function (): void {
    config(['walle.contingencias.ativo' => true]);
    $amostra = require base_path('tests/Fixtures/legado/robos/contingencia.php');
    $cliente = criarClienteHelpDesk(['consultoria' => 'S']);
    foreach ($amostra['sactus'] as $dados) {
        criarContingenciaSactus($dados);
    }
    servidorDoClienteNoBancoDeTestes();

    app(RoboContingencias::class)->rodarCiclo();

    expect((array) DB::table('painel_contingency_crawler')->where('fk_c_cliente', $cliente)->sole())
        ->toMatchArray($amostra['linha']);
});

test('Task Scheduler grava T0s= como 220 para execução bem sucedida', function (): void {
    config(['walle.tarefas.ativo' => true]);
    $amostra = require base_path('tests/Fixtures/legado/robos/task-scheduler.php');
    servidorDoClienteNoBancoDeTestes();
    $cliente = criarClienteHelpDesk();
    $tarefa = DB::table('task_scheduler_querys')->insertGetId($amostra['tarefa']);

    app(RoboTarefas::class)->rodarPendentes();

    expect(DB::table('task_scheduler_querys_log')->where(['fk_task_scheduler_querys' => $tarefa, 'fk_c_cliente' => $cliente])->value('log_query'))
        ->toBe('T0s=');
});

test('robôs desligados não fazem chamadas nem gravações', function (): void {
    config(['walle.sentinela.ativo' => false, 'walle.cleantax.ativo' => false, 'walle.contingencias.ativo' => false, 'walle.tarefas.ativo' => false]);
    Http::fake();

    expect(app(RoboSentinela::class)->ligado())->toBeFalse()
        ->and(app(RoboCleanTax::class)->ligado())->toBeFalse()
        ->and(app(RoboContingencias::class)->ligado())->toBeFalse()
        ->and(app(RoboTarefas::class)->ligado())->toBeFalse();
    Http::assertNothingSent();
});
