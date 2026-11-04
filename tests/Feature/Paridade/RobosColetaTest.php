<?php

use App\CleanTax\RoboCleanTax;
use App\Contingencias\Cliente\ConectorDeCliente;
use App\Contingencias\Cliente\ConexaoComCliente;
use App\Contingencias\Cliente\FalhaConexaoCliente;
use App\Contingencias\RoboContingencias;
use App\Ferramentas\RoboTarefas;
use App\Models\RoboConfig;
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
    RoboConfig::updateOrCreate(
        ['robo' => 'sentinela', 'parametro' => 'ativo'],
        ['valor' => 'true', 'valor_sanitizado' => 'true']
    );
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
    RoboConfig::updateOrCreate(
        ['robo' => 'cleantax', 'parametro' => 'ativo'],
        ['valor' => 'true', 'valor_sanitizado' => 'true']
    );
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
    RoboConfig::updateOrCreate(
        ['robo' => 'contingencias', 'parametro' => 'ativo'],
        ['valor' => 'true', 'valor_sanitizado' => 'true']
    );
    $amostra = require base_path('tests/Fixtures/legado/robos/contingencia.php');
    $cliente = criarClienteHelpDesk(['consultoria' => 'S']);
    criarContingenciaSactus($amostra['entrada_sactus']['cabecalho'], [[]], $amostra['entrada_sactus']['chave']);
    servidorDoClienteNoBancoDeTestes();

    $agoraDoBancoAntes = DB::selectOne('select now() as agora')->agora;
    app(RoboContingencias::class)->rodarCiclo();
    $agoraDoBancoDepois = DB::selectOne('select now() as agora')->agora;

    $painelEsperado = ['fk_c_cliente' => $cliente, ...$amostra['painel']];
    $paineis = DB::table('painel_contingency_crawler')->get();
    expect($paineis)->toHaveCount(1)
        ->and((array) $paineis->sole())->toEqual($painelEsperado);

    $historicoEsperado = ['fk_c_cliente' => $cliente, ...$amostra['historico'][0]];
    $historico = DB::table('history_contingencia')->where('fk_c_cliente', $cliente)->get()->map(fn ($linha): array => (array) $linha)->all();
    expect($historico)->toHaveCount(1);

    $dataClique = $historico[0]['data_clique'];
    $historicoEsperado['data_clique'] = $dataClique;
    expect($historico[0])->toEqual($historicoEsperado)
        ->and($dataClique)->toBeString()->toMatch('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/')
        ->and(Carbon::createFromFormat('Y-m-d H:i:s', $dataClique)->betweenIncluded(Carbon::parse($agoraDoBancoAntes), Carbon::parse($agoraDoBancoDepois)))->toBeTrue();
});

test('Task Scheduler grava log de sucesso igual ao 220', function (): void {
    config(['walle.tarefas.ativo' => true]);
    RoboConfig::updateOrCreate(
        ['robo' => 'tarefas', 'parametro' => 'ativo'],
        ['valor' => 'true', 'valor_sanitizado' => 'true']
    );
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

test('Contingência falha de conexão preserva métrica e histórico válidos e registra falha (desvio aprovado)', function (): void {
    // 220: contingency.crawler.php:365 (!connect()) + set.history.cont.php:566 DELETE + 568-570 REPLACE zerado.
    // Walle: SPEC.md 5.2 "falhas mantêm a última métrica válida", PLAN.md task 3.1, relatorio-tecnico.md.
    // Na falha NÃO zera nem apaga; preserva painel/histórico prévios e registra coleta falha em contingencias_coletas.
    config(['walle.contingencias.ativo' => true]);
    RoboConfig::updateOrCreate(
        ['robo' => 'contingencias', 'parametro' => 'ativo'],
        ['valor' => 'true', 'valor_sanitizado' => 'true']
    );
    $amostra = require base_path('tests/Fixtures/legado/robos/contingencia.php');
    $cliente = criarClienteHelpDesk(['consultoria' => 'S']);
    criarContingenciaSactus($amostra['entrada_sactus']['cabecalho'], [[]], $amostra['entrada_sactus']['chave']);
    // Insere métrica e histórico prévios válidos (como se ciclo anterior tivesse ido bem)
    DB::table('painel_contingency_crawler')->insert(['fk_c_cliente' => $cliente, ...$amostra['erro_conexao']['painel_previo']]);
    DB::table('history_contingencia')->insert(['fk_c_cliente' => $cliente, ...$amostra['erro_conexao']['historico_previo']]);
    $this->mock(ConectorDeCliente::class)
        ->shouldReceive('conectar')
        ->once()
        ->andThrow(FalhaConexaoCliente::servidorNaoResponde('teste'));

    app(RoboContingencias::class)->rodarCiclo();

    // Painel preservado (não zerado, não apagado)
    $paineis = DB::table('painel_contingency_crawler')->where('fk_c_cliente', $cliente)->get();
    expect($paineis)->toHaveCount(1)
        ->and((array) $paineis->sole())->toEqual(['fk_c_cliente' => $cliente, ...$amostra['erro_conexao']['painel_previo']]);

    // Histórico preservado (não apagado) — data_clique e data_consulta_sefaz_bot são colunas do banco, ignoradas na comparação.
    $historico = DB::table('history_contingencia')->where('fk_c_cliente', $cliente)->get();
    expect($historico)->toHaveCount(1);
    $h = collect((array) $historico->sole())->except(['data_clique', 'data_consulta_sefaz_bot'])->toArray();
    expect($h)->toEqual(['fk_c_cliente' => $cliente, ...$amostra['erro_conexao']['historico_previo']]);

    // Coleta de falha registrada localmente (auditoria separada)
    $coleta = DB::table('contingencias_coletas')->where('fk_c_cliente', $cliente)->sole();
    expect($coleta->status)->toBe('falha')->and($coleta->erro)->not->toBeEmpty();
});

test('Task Scheduler falha de conexão grava prefixo FALHA sem segredo (desvio por segurança aprovado)', function (): void {
    // 220: task.scheduler.bot.php:183/202/241 grava "FALHA AO CONECTAR NO CLIENTE: host | user | db | pass | porta" (senha em claro).
    // Walle: RoboTarefas.php docblock "Diferenças: ... não leva usuário nem senha", RoboTarefasTest.php linha 147.
    // O retry tipo_bot=2 do 220 filtra SUBSTRING(log_query,1,5)='FALHA' (linha 22) — prefixo mantido.
    config(['walle.tarefas.ativo' => true]);
    RoboConfig::updateOrCreate(
        ['robo' => 'tarefas', 'parametro' => 'ativo'],
        ['valor' => 'true', 'valor_sanitizado' => 'true']
    );
    $amostra = require base_path('tests/Fixtures/legado/robos/task-scheduler.php');
    $cliente = criarClienteHelpDesk([], $amostra['cadastro_servidor']);
    $tarefa = DB::table('task_scheduler_querys')->insertGetId($amostra['tarefa']);
    $this->mock(ConectorDeCliente::class)
        ->shouldReceive('conectar')
        ->once()
        ->andThrow(FalhaConexaoCliente::servidorNaoResponde('teste'));

    app(RoboTarefas::class)->rodarPendentes();

    $logs = DB::table('task_scheduler_querys_log')->get();
    expect($logs)->toHaveCount(1);
    $log = (array) $logs->sole();
    expect($log['fk_task_scheduler_querys'])->toBe($tarefa)
        ->and($log['fk_c_cliente'])->toBe($cliente)
        ->and($log['log_query'])->toStartWith($amostra['prefixo_falha_conexao'])
        ->and($log['log_query'])->not->toContain('SenhaFicticia-123') // senha NÃO vazou
        ->and($log['log_query'])->not->toContain('usuario_ficticio') // usuário NÃO vazou
        ->and($log['log_query'])->not->toContain('banco_ficticio') // banco NÃO vazou
        ->and(Carbon::parse($log['data_time']))->toBeInstanceOf(Carbon::class);
});

test('Task Scheduler erro de SQL grava base64 de "Erro: <msg MySQL>" (formato, não texto literal)', function (): void {
    // 220: task.scheduler.bot.php:213-227 log = base64("Erro: " . $mysqli->error). Texto depende do MySQL/banco.
    // Compara formato (base64 + prefixo "Erro: ") e prefixo da mensagem, não o texto exato.
    config(['walle.tarefas.ativo' => true]);
    RoboConfig::updateOrCreate(
        ['robo' => 'tarefas', 'parametro' => 'ativo'],
        ['valor' => 'true', 'valor_sanitizado' => 'true']
    );
    $amostra = require base_path('tests/Fixtures/legado/robos/task-scheduler.php');
    $cliente = criarClienteHelpDesk();
    $tarefa = DB::table('task_scheduler_querys')->insertGetId(array_merge($amostra['tarefa'], ['query_execute' => base64_encode($amostra['query_com_erro_sql'])]));
    $conexaoFalsa = new ConexaoComCliente(DB::connection(), DB::getDatabaseName(), false);
    $this->mock(ConectorDeCliente::class)
        ->shouldReceive('conectar')
        ->once()
        ->andReturn($conexaoFalsa);

    app(RoboTarefas::class)->rodarPendentes();

    $logs = DB::table('task_scheduler_querys_log')->get();
    expect($logs)->toHaveCount(1);
    $log = (array) $logs->sole();
    $decoded = base64_decode($log['log_query']);
    expect($decoded)->toStartWith($amostra['log_erro_query_prefixo'])
        ->and($decoded)->toMatch($amostra['log_erro_query_padrao'])
        ->and($log['fk_task_scheduler_querys'])->toBe($tarefa)
        ->and($log['fk_c_cliente'])->toBe($cliente)
        ->and(Carbon::parse($log['data_time']))->toBeInstanceOf(Carbon::class);
});

test('Task Scheduler sucesso grava "T0s=" igual ao 220', function (): void {
    config(['walle.tarefas.ativo' => true]);
    RoboConfig::updateOrCreate(
        ['robo' => 'tarefas', 'parametro' => 'ativo'],
        ['valor' => 'true', 'valor_sanitizado' => 'true']
    );
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
