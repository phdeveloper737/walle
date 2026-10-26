<?php

use App\Contingencias\Cliente\ConectorDeCliente;
use App\Contingencias\Cliente\ConexaoComCliente;
use App\Contingencias\Cliente\FalhaConexaoCliente;
use App\Contingencias\RoboContingencias;
use App\Jobs\RodarCicloContingencias;
use App\Models\Auditoria;
use App\Models\CicloContingencias;
use App\Models\ColetaContingencias;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;

test('coleta três contingências integrais e registra métricas, ciclo e ator automático', function () {
    $this->travelTo(now()->setDate(2026, 10, 24)->setTime(10, 0));
    config(['walle.contingencias.ativo' => true]);
    Http::preventStrayRequests();
    $cliente = criarClienteHelpDesk(['consultoria' => 'S']);
    criarContingenciaVersatus($cliente, ['cupom' => '100', 'chave' => str_repeat('1', 44)]);
    criarContingenciaVersatus($cliente, ['cupom' => '101', 'motivo' => 'Nao Enviada para Sefaz']);
    $sactus = criarContingenciaSactus(['cupom' => '501']);
    criarContingenciaSactus(['cupom' => '502']);
    autorizarNaSefaz($cliente, $sactus, now()->toDateString());
    servidorDoClienteNoBancoDeTestes();

    app(RoboContingencias::class)->rodarCiclo();

    $this->assertDatabaseCount('history_contingencia', 3);
    $this->assertDatabaseHas('history_contingencia', ['fk_c_cliente' => $cliente, 'chave' => $sactus, 'software' => 'SACTUS']);
    $this->assertDatabaseHas('painel_contingency_crawler', ['fk_c_cliente' => $cliente, 'total_cliente' => 3, 'total_bot' => 3, 'total_sactus' => 2, 'total_versatus' => 1]);
    $ciclo = CicloContingencias::query()->sole();
    expect($ciclo->status)->toBe('concluido')->and($ciclo->elegiveis)->toBe(1)->and($ciclo->coletados)->toBe(1)->and($ciclo->falhas)->toBe(0);
    expect($ciclo->terminado_em)->not->toBeNull();
    $coleta = ColetaContingencias::query()->sole();
    expect($coleta->status)->toBe('sucesso')->and($coleta->total_bot)->toBe(3);
    $auditoria = Auditoria::query()->where('acao', 'contingencias.coletar')->sole();
    expect($auditoria->fk_c_cliente)->toBe($cliente)->and($auditoria->detalhes)->toMatchArray(['origem' => 'automatica', 'robo' => 'contingencias', 'ciclo_id' => $ciclo->id, 'coleta_id' => $coleta->id, 'status' => 'sucesso']);
    Http::assertNothingSent();
});

test('falha preserva histórico e métricas do cliente e não interrompe o próximo', function () {
    config(['walle.contingencias.ativo' => true]);
    $sucesso = criarClienteHelpDesk(['consultoria' => 'S']);
    $falha = criarClienteHelpDesk(['consultoria' => 'S']);
    criarContingenciaVersatus($sucesso);
    DB::table('history_contingencia')->insert(['fk_c_cliente' => $falha, 'chave' => str_repeat('8', 44), 'software' => 'SACTUS']);
    DB::table('painel_contingency_crawler')->insert(['fk_c_cliente' => $falha, 'total_bot' => 7]);
    $conector = $this->mock(ConectorDeCliente::class);
    $conector->shouldReceive('conectar')->with($falha, Mockery::type('array'))->once()->andThrow(new FalhaConexaoCliente('ERRO_MYSQL', 'password=segredo token=privado', 'SQL sensível'));
    $conector->shouldReceive('conectar')->with($sucesso, Mockery::type('array'))->once()->andReturn(new ConexaoComCliente(DB::connection(), DB::getDatabaseName(), false));

    app(RoboContingencias::class)->rodarCiclo();

    $this->assertDatabaseHas('history_contingencia', ['fk_c_cliente' => $falha, 'chave' => str_repeat('8', 44)]);
    $this->assertDatabaseHas('painel_contingency_crawler', ['fk_c_cliente' => $falha, 'total_bot' => 7]);
    $this->assertDatabaseHas('painel_contingency_crawler', ['fk_c_cliente' => $sucesso, 'total_bot' => 1]);
    $coleta = ColetaContingencias::query()->where('fk_c_cliente', $falha)->sole();
    expect($coleta->status)->toBe('falha')->and($coleta->total_bot)->toBeNull()->and($coleta->erro)->not->toContain('segredo', 'privado', 'SQL');
    $ciclo = CicloContingencias::query()->sole();
    expect($ciclo->falhas)->toBe(1)->and($ciclo->coletados)->toBe(1);
    expect(Auditoria::query()->get()->toJson())->not->toContain('segredo', 'privado', 'SQL sensível');
});

test('robô, job e coleta manual desligados não consultam clientes nem gravam ciclos', function () {
    config(['walle.contingencias.ativo' => false]);
    Http::preventStrayRequests();
    $this->mock(ConectorDeCliente::class)->shouldNotReceive('conectar');
    $robo = app(RoboContingencias::class);

    $robo->rodarCiclo();
    app(RodarCicloContingencias::class)->handle($robo);
    $resultado = $robo->coletarCliente(123);

    expect($resultado->avisoSactus)->toContain('desligado');
    $this->assertDatabaseCount('contingencias_ciclos', 0);
    $this->assertDatabaseCount('contingencias_coletas', 0);
    $this->assertDatabaseCount('auditoria', 0);
    Http::assertNothingSent();
});

test('coleta mais de mil registros sem o cache ou limite da projeção de tela', function (bool $comChave) {
    config(['walle.contingencias.ativo' => true]);
    $cliente = criarClienteHelpDesk(['consultoria' => 'S']);
    $linhas = [];
    for ($numero = 1; $numero <= 1001; $numero++) {
        $linhas[] = ['store' => '1', 'pdv' => '2', 'cupom' => (string) $numero, 'fiscal_date' => '2026-10-24 10:00:00', 'motivo' => 'Rejeição', 'chave_acesso_nfce' => $comChave ? '3'.str_pad((string) $numero, 43, '0', STR_PAD_LEFT) : null];
    }
    DB::table('contingencias_pdv')->insert($linhas);
    servidorDoClienteNoBancoDeTestes();

    $resultado = app(RoboContingencias::class)->coletarCliente($cliente);

    expect($resultado->pendentes)->toHaveCount(1001);
    $this->assertDatabaseCount('history_contingencia', 1001);
    $this->assertDatabaseHas('painel_contingency_crawler', ['fk_c_cliente' => $cliente, 'total_sactus' => 1001]);
})->with(['com chaves NFC-e distintas' => true, 'sem chave NFC-e' => false]);

test('zero confirmado limpa o histórico sem apagar as coletas anteriores', function () {
    config(['walle.contingencias.ativo' => true]);
    $cliente = criarClienteHelpDesk(['consultoria' => 'S']);
    criarContingenciaSactus();
    servidorDoClienteNoBancoDeTestes();
    $robo = app(RoboContingencias::class);
    $robo->rodarCiclo();
    DB::table('contingencias_pdv')->delete();

    $robo->rodarCiclo();

    $this->assertDatabaseCount('history_contingencia', 0);
    $this->assertDatabaseHas('painel_contingency_crawler', ['fk_c_cliente' => $cliente, 'total_bot' => 0]);
    expect(ColetaContingencias::query()->orderBy('id')->pluck('total_bot')->all())->toBe([1, 0]);
});

test('mantém paridade Sactus com quantidade igual e atualiza Versatus em cada coleta', function () {
    config(['walle.contingencias.ativo' => true]);
    $cliente = criarClienteHelpDesk(['consultoria' => 'S']);
    $chave = criarContingenciaSactus(['motivo' => 'Motivo antigo']);
    criarContingenciaVersatus($cliente, ['motivo' => 'Versatus antigo']);
    servidorDoClienteNoBancoDeTestes();
    $robo = app(RoboContingencias::class);
    $robo->rodarCiclo();
    DB::table('contingencias_pdv')->update(['motivo' => 'Motivo novo']);
    DB::table('contingencias_versatus_venda')->update(['motivo' => 'Versatus novo']);

    $robo->rodarCiclo();

    $this->assertDatabaseCount('history_contingencia', 2);
    $this->assertDatabaseHas('history_contingencia', ['chave' => $chave, 'motivo' => 'Motivo antigo']);
    $this->assertDatabaseHas('history_contingencia', ['fk_c_cliente' => $cliente, 'software' => 'VERSATUS', 'motivo' => 'Versatus novo']);
});

test('cliente desativado no painel perde somente seus dados locais sem conexão externa', function () {
    config(['walle.contingencias.ativo' => true]);
    $cliente = criarClienteHelpDesk(['consultoria' => 'S']);
    DB::table('informacao_tecnica_painel_contingencias')->insert(['fk_c_cliente' => $cliente, 'ativo_painel_contingencias' => 'N']);
    DB::table('history_contingencia')->insert(['fk_c_cliente' => $cliente, 'chave' => 'antiga']);
    DB::table('painel_contingency_crawler')->insert(['fk_c_cliente' => $cliente, 'total_bot' => 4]);
    $this->mock(ConectorDeCliente::class)->shouldNotReceive('conectar');

    app(RoboContingencias::class)->rodarCiclo();

    $this->assertDatabaseMissing('history_contingencia', ['fk_c_cliente' => $cliente]);
    $this->assertDatabaseMissing('painel_contingency_crawler', ['fk_c_cliente' => $cliente]);
    $this->assertDatabaseHas('c_cliente', ['id' => $cliente, 'consultoria' => 'S'], 'help');
});

test('Sactus zerado mantém o histórico anterior quando ainda há Versatus como no legado', function () {
    config(['walle.contingencias.ativo' => true]);
    $cliente = criarClienteHelpDesk(['consultoria' => 'S']);
    DB::table('history_contingencia')->insert(['fk_c_cliente' => $cliente, 'chave' => 'historico_sactus', 'software' => 'SACTUS']);
    criarContingenciaVersatus($cliente);
    servidorDoClienteNoBancoDeTestes();

    app(RoboContingencias::class)->rodarCiclo();

    $this->assertDatabaseCount('history_contingencia', 2);
    $this->assertDatabaseHas('history_contingencia', ['fk_c_cliente' => $cliente, 'chave' => 'historico_sactus', 'software' => 'SACTUS']);
    $this->assertDatabaseHas('painel_contingency_crawler', ['fk_c_cliente' => $cliente, 'total_cliente' => 1, 'total_sactus' => 0, 'total_versatus' => 1]);
});

test('trava ocupada impede duas coletas do mesmo cliente', function () {
    config(['walle.contingencias.ativo' => true]);
    $cliente = criarClienteHelpDesk(['consultoria' => 'S']);
    $this->mock(ConectorDeCliente::class)->shouldNotReceive('conectar');
    $trava = Cache::lock('contingencias:cliente:'.$cliente, 3700);
    $trava->get();

    try {
        $resultado = app(RoboContingencias::class)->coletarCliente($cliente);
    } finally {
        $trava->release();
    }

    expect($resultado->avisoSactus)->toContain('ocupado');
    $this->assertDatabaseHas('contingencias_coletas', ['fk_c_cliente' => $cliente, 'status' => 'falha']);
    $this->assertDatabaseCount('painel_contingency_crawler', 0);
});

test('job único na conexão robos executa ciclo quando ligado', function () {
    $job = new RodarCicloContingencias;
    $robo = $this->mock(RoboContingencias::class);
    $robo->shouldReceive('ligado')->once()->andReturn(true);
    $robo->shouldReceive('rodarCiclo')->once();

    $job->handle($robo);

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)->and($job->connection)->toBe('robos')->and($job->queue)->toBe('contingencias')->and($job->timeout)->toBeLessThan(3700);
});

test('sucesso posterior atualiza métricas sem apagar a coleta que falhou', function () {
    config(['walle.contingencias.ativo' => true]);
    $cliente = criarClienteHelpDesk(['consultoria' => 'S']);
    criarContingenciaSactus();
    DB::table('painel_contingency_crawler')->insert(['fk_c_cliente' => $cliente, 'total_bot' => 7]);
    $tentativa = 0;
    $this->mock(ConectorDeCliente::class)->shouldReceive('conectar')->twice()->andReturnUsing(function () use (&$tentativa): ConexaoComCliente {
        if (++$tentativa === 1) {
            throw FalhaConexaoCliente::servidorNaoResponde('detalhe privado');
        }

        return new ConexaoComCliente(DB::connection(), DB::getDatabaseName(), false);
    });
    $robo = app(RoboContingencias::class);
    $robo->rodarCiclo();
    $primeiraColeta = ColetaContingencias::query()->sole();

    $robo->rodarCiclo();

    expect(ColetaContingencias::query()->orderBy('id')->pluck('status')->all())->toBe(['falha', 'sucesso']);
    expect($primeiraColeta->fresh()->erro)->toBe('O servidor do cliente não respondeu.');
    $this->assertDatabaseHas('painel_contingency_crawler', ['fk_c_cliente' => $cliente, 'total_bot' => 1]);
    $this->assertDatabaseCount('history_contingencia', 1);
    $this->assertDatabaseCount('auditoria', 2);
});

test('trava ocupada preserva os dados do inativo e permite coletar o próximo cliente', function () {
    config(['walle.contingencias.ativo' => true]);
    $ativo = criarClienteHelpDesk(['consultoria' => 'S']);
    $inativo = criarClienteHelpDesk(['consultoria' => 'N']);
    DB::table('history_contingencia')->insert(['fk_c_cliente' => $inativo, 'chave' => 'antiga']);
    DB::table('painel_contingency_crawler')->insert(['fk_c_cliente' => $inativo, 'total_bot' => 4]);
    $this->mock(ConectorDeCliente::class)->shouldReceive('conectar')->with($ativo, Mockery::type('array'))->once()
        ->andReturn(new ConexaoComCliente(DB::connection(), DB::getDatabaseName(), false));
    $trava = Cache::lock('contingencias:cliente:'.$inativo, 3700);
    $trava->get();

    try {
        app(RoboContingencias::class)->rodarCiclo();
    } finally {
        $trava->release();
    }

    $this->assertDatabaseHas('history_contingencia', ['fk_c_cliente' => $inativo, 'chave' => 'antiga']);
    $this->assertDatabaseHas('painel_contingency_crawler', ['fk_c_cliente' => $inativo, 'total_bot' => 4]);
    $this->assertDatabaseHas('contingencias_coletas', ['fk_c_cliente' => $inativo, 'status' => 'falha']);
    $this->assertDatabaseHas('contingencias_coletas', ['fk_c_cliente' => $ativo, 'status' => 'sucesso']);
    expect(CicloContingencias::query()->sole()->coletados)->toBe(1);
});

test('falha ao persistir reverte histórico e métricas e reporta somente mensagem segura', function () {
    config(['walle.contingencias.ativo' => true]);
    $cliente = criarClienteHelpDesk(['consultoria' => 'S']);
    criarContingenciaSactus(['cupom' => '501']);
    criarContingenciaSactus(['cupom' => '502']);
    criarContingenciaVersatus($cliente);
    DB::table('history_contingencia')->insert(['fk_c_cliente' => $cliente, 'chave' => 'historico_valido', 'software' => 'SACTUS']);
    DB::table('painel_contingency_crawler')->insert(['fk_c_cliente' => $cliente, 'total_bot' => 7]);
    servidorDoClienteNoBancoDeTestes();
    Exceptions::fake();
    $evento = 'eloquent.creating: '.Auditoria::class;
    Event::listen($evento, function (Auditoria $auditoria): void {
        if (($auditoria->detalhes['status'] ?? '') === 'sucesso') {
            throw new RuntimeException('password=segredo_de_teste SQL sensível');
        }
    });

    try {
        app(RoboContingencias::class)->rodarCiclo();
    } finally {
        Event::forget($evento);
    }

    $this->assertDatabaseCount('history_contingencia', 1);
    $this->assertDatabaseHas('history_contingencia', ['fk_c_cliente' => $cliente, 'chave' => 'historico_valido']);
    $this->assertDatabaseHas('painel_contingency_crawler', ['fk_c_cliente' => $cliente, 'total_bot' => 7]);
    expect(ColetaContingencias::query()->sole()->status)->toBe('falha');
    expect(ColetaContingencias::query()->sole()->total_bot)->toBeNull();
    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(fn (RuntimeException $erro): bool => $erro->getPrevious() === null
        && $erro->getMessage() === 'Não foi possível concluir a coleta de contingências do cliente.');
    expect(Auditoria::query()->sole()->detalhes['status'])->toBe('falha');
});

test('coleta ambos os softwares da loja sem depender de itens ou usar chave vazia no histórico', function () {
    config(['walle.contingencias.ativo' => true]);
    $cliente = criarClienteHelpDesk(['consultoria' => 'S']);
    DB::table('informacao_tecnica_painel_contingencias')->insert(['fk_c_cliente' => $cliente, 'tips_pdv' => 'V']);
    criarContingenciaSactus(chaveNaContingencia: false);
    criarContingenciaSactus(['store' => '2', 'cupom' => '999']);
    criarContingenciaVersatus($cliente, ['cupom' => '000123', 'chave' => ''], []);
    servidorDoClienteNoBancoDeTestes();

    app(RoboContingencias::class)->rodarCiclo();

    $this->assertDatabaseCount('history_contingencia', 2);
    $this->assertDatabaseHas('history_contingencia', ['fk_c_cliente' => $cliente, 'software' => 'VERSATUS', 'numero' => '123', 'chave' => '123']);
    $sactus = DB::table('history_contingencia')->where('software', 'SACTUS')->sole();
    expect($sactus->numero)->toBe('500')->and($sactus->chave)->not->toBeEmpty();
    $this->assertDatabaseHas('painel_contingency_crawler', ['fk_c_cliente' => $cliente, 'total_sactus' => 1, 'total_versatus' => 1]);
});

test('cliente sem cadastro técnico registra falha segura e preserva os dados válidos sem conectar', function () {
    config(['walle.contingencias.ativo' => true]);
    $cliente = criarClienteHelpDesk(['consultoria' => 'S']);
    DB::connection('help')->table('informacao_tecnica_dataserver')->where('fk_c_cliente', $cliente)->delete();
    DB::table('history_contingencia')->insert(['fk_c_cliente' => $cliente, 'chave' => 'historico_valido']);
    DB::table('painel_contingency_crawler')->insert(['fk_c_cliente' => $cliente, 'total_bot' => 7]);
    $this->mock(ConectorDeCliente::class)->shouldNotReceive('conectar');

    $resultado = app(RoboContingencias::class)->coletarCliente($cliente);

    expect($resultado->avisoSactus)->toBe('O cliente não tem cadastro técnico de servidor no Help Desk.');
    $coleta = ColetaContingencias::query()->sole();
    expect($coleta->status)->toBe('falha')->and($coleta->total_bot)->toBeNull()->and($coleta->erro)->toBe($resultado->avisoSactus);
    expect(CicloContingencias::query()->sole()->falhas)->toBe(1);
    $this->assertDatabaseCount('history_contingencia', 1);
    $this->assertDatabaseHas('history_contingencia', ['fk_c_cliente' => $cliente, 'chave' => 'historico_valido']);
    $this->assertDatabaseHas('painel_contingency_crawler', ['fk_c_cliente' => $cliente, 'total_bot' => 7]);
    expect(Auditoria::query()->sole()->detalhes['origem'])->toBe('manual');
});
