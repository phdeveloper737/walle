<?php

use App\CleanTax\RoboCleanTax;
use App\Contingencias\Cliente\ConectorDeCliente;
use App\Contingencias\Cliente\ConexaoComCliente;
use App\Contingencias\RoboContingencias;
use App\Ferramentas\RoboTarefas;
use App\Sentinela\RoboSentinela;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function (): void {
    $this->travelTo(now()->setDate(2026, 10, 27)->setTime(10, 0));
    Http::preventStrayRequests();
    Sleep::fake();
});

test('Sentinela grava linha completa de sucesso e erro igual ao 220', function (): void {
    config(['walle.sentinela.ativo' => true]);
    $amostra = require base_path('tests/Fixtures/legado/robos/sentinela-ok.php');
    $clienteOk = criarClienteHelpDesk([], ['ip_externo_servidor' => $amostra['host_sucesso']]);
    $clienteErro = criarClienteHelpDesk([], ['ip_externo_servidor' => $amostra['host_erro']]);

    Http::fake([
        'http://'.$amostra['host_sucesso'].'/sentinela.php*' => Http::response($amostra['json'], 200),
        'http://'.$amostra['host_erro'].'/sentinela.php*' => fn () => throw new ConnectionException('cURL error 28: Connection timed out after 8001 milliseconds'),
    ]);

    app(RoboSentinela::class)->rodarCiclo();

    // Normalização necessária: id é autoincremento do walle; duracao_ms vem do relógio real
    // (microtime), que travelTo() não controla; json é comparado decodificado porque a ordem
    // das chaves do 220 difere da serialização do PHP.
    $linhasSentinela = DB::table('painel_sentinela')->orderBy('fk_c_cliente')->get();
    expect($linhasSentinela)->toHaveCount(2);

    $linhaOk = $linhasSentinela->firstWhere('fk_c_cliente', $clienteOk);
    $obtidoOk = collect((array) $linhaOk)->except(['id', 'duracao_ms', 'json'])->toArray();
    expect($obtidoOk)->toEqual(['fk_c_cliente' => $clienteOk, ...collect($amostra['linha_sucesso'])->except(['fk_c_cliente', 'duracao_ms', 'json'])->toArray()])
        ->and(json_decode((string) $linhaOk->json, true))->toEqual(json_decode($amostra['json'], true))
        ->and($linhaOk->duracao_ms)->toBeInt()->toBeGreaterThanOrEqual(0);

    $linhaErro = $linhasSentinela->firstWhere('fk_c_cliente', $clienteErro);
    $obtidoErro = collect((array) $linhaErro)->except(['id', 'duracao_ms'])->toArray();
    expect($obtidoErro)->toEqual(['fk_c_cliente' => $clienteErro, ...collect($amostra['linha_erro'])->except(['duracao_ms'])->toArray()])
        ->and($linhaErro->duracao_ms)->toBeInt()->toBeGreaterThanOrEqual(0);
});

test('CleanTax grava linhas completas de sucesso e erro igual ao 220', function (): void {
    config(['walle.cleantax.ativo' => true]);
    $amostra = require base_path('tests/Fixtures/legado/robos/cleantax.php');
    Http::fake([
        RoboCleanTax::API_ENDPOINT => Http::response(['status' => true, 'message' => [$amostra['cliente_sucesso'], $amostra['cliente_erro']]], 200),
        'http://'.$amostra['cliente_sucesso']['servidor'].'*' => Http::response($amostra['resposta_sucesso'], 200),
        'http://'.$amostra['cliente_erro']['servidor'].'*' => fn () => throw new ConnectionException('cURL error 28: Connection timed out'),
    ]);

    app(RoboCleanTax::class)->rodarCiclo();

    $linhasCleanTax = DB::table('painel_cleantax_worker')->orderBy('fk_c_cliente')->get();
    expect($linhasCleanTax)->toHaveCount(2);

    foreach (['sucesso', 'erro'] as $tipo) {
        $cliente = $amostra['cliente_'.$tipo];
        $linha = $linhasCleanTax->firstWhere('fk_c_cliente', $cliente['fk_c_cliente']);
        $obtido = collect((array) $linha)->except(['tempo_execucao_segundos'])->toArray();
        $esperado = collect($amostra['linha_'.$tipo])->except(['tempo_execucao_segundos'])->toArray();
        $esperado['data'] = '2026-10-27 10:00:00';
        expect($obtido)->toEqual($esperado)
            ->and($linha->tempo_execucao_segundos)->toMatch('/^\d+\.\d{3}$/')
            ->and((float) $linha->tempo_execucao_segundos)->toBeGreaterThanOrEqual(0.0);
    }
});

test('Contingência grava painel e histórico completos iguais ao 220', function (): void {
    config(['walle.contingencias.ativo' => true]);
    $amostra = require base_path('tests/Fixtures/legado/robos/contingencia.php');
    $cliente = criarClienteHelpDesk(['consultoria' => 'S']);
    criarContingenciaSactus($amostra['entrada_sactus']['cabecalho'], [[]], $amostra['entrada_sactus']['chave']);
    servidorDoClienteNoBancoDeTestes();

    app(RoboContingencias::class)->rodarCiclo();

    $painelEsperado = ['fk_c_cliente' => $cliente, ...$amostra['painel']];
    $paineis = DB::table('painel_contingency_crawler')->get();
    expect($paineis)->toHaveCount(1)
        ->and((array) $paineis->sole())->toEqual($painelEsperado);

    $historicoEsperado = [['fk_c_cliente' => $cliente, ...$amostra['historico'][0]]];
    $historico = DB::table('history_contingencia')->where('fk_c_cliente', $cliente)->get()->map(fn ($linha): array => (array) $linha)->all();
    expect($historico)->toHaveCount(1)
        ->and(collect($historico[0])->except(['data_clique'])->toArray())
        ->toEqual(collect($historicoEsperado[0])->except(['data_clique'])->toArray())
        ->and($historico[0]['data_clique'])->not->toBeNull()
        ->and(Carbon::parse($historico[0]['data_clique']))->toBeInstanceOf(Carbon::class);
});

test('Task Scheduler grava log de sucesso igual ao 220', function (): void {
    config(['walle.tarefas.ativo' => true]);
    $amostra = require base_path('tests/Fixtures/legado/robos/task-scheduler.php');
    $cliente = criarClienteHelpDesk();
    $tarefa = DB::table('task_scheduler_querys')->insertGetId($amostra['tarefa']);
    $this->mock(ConectorDeCliente::class)->shouldReceive('conectar')->once()
        ->andReturn(new ConexaoComCliente(DB::connection(), DB::getDatabaseName(), false));

    app(RoboTarefas::class)->rodarPendentes();

    $logs = DB::table('task_scheduler_querys_log')->get();
    expect($logs)->toHaveCount(1);
    $log = (array) $logs->sole();
    expect($log['fk_task_scheduler_querys'])->toBe($tarefa)
        ->and($log['fk_c_cliente'])->toBe($cliente)
        ->and($log['log_query'])->toBe($amostra['log_ok'])
        ->and(Carbon::parse($log['data_time']))->toBeInstanceOf(Carbon::class);
});

test('robôs desligados executados não chamam nem gravam', function (): void {
    config(['walle.sentinela.ativo' => false, 'walle.cleantax.ativo' => false, 'walle.contingencias.ativo' => false, 'walle.tarefas.ativo' => false]);
    criarClienteHelpDesk([], ['ip_externo_servidor' => '10.8.0.250']);
    Http::fake();

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
