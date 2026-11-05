<?php

use App\Contingencias\Cliente\ConectorDeCliente;
use App\Contingencias\Cliente\FalhaConexaoCliente;
use App\Contingencias\RoboContingencias;
use App\Models\Auditoria;
use App\Models\ColetaContingencias;
use App\Models\RoboConfig;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;

const SENHA_DO_FIXTURE_DETALHE = 'S3nh4-Muito-Dist1nta';

beforeEach(function () {
    config(['walle.contingencias.ativo' => true]);
    RoboConfig::updateOrCreate(['robo' => 'contingencias', 'parametro' => 'ativo'], ['valor' => 'true', 'valor_sanitizado' => 'true']);
});

function coletaParaRetencao(int $cliente, string $status): int
{
    $ciclo = (int) DB::table('contingencias_ciclos')->insertGetId(['iniciado_em' => now()]);

    return (int) DB::table('contingencias_coletas')->insertGetId([
        'ciclo_id' => $ciclo, 'fk_c_cliente' => $cliente, 'iniciado_em' => now(), 'status' => $status,
    ]);
}

test('falha grava o detalhe técnico com a tentativa e sem a senha em lugar nenhum', function () {
    $cliente = criarClienteHelpDesk(['consultoria' => 'S'], ['senha_mysql' => SENHA_DO_FIXTURE_DETALHE]);
    Log::spy();
    $this->mock(ConectorDeCliente::class)->shouldReceive('conectar')->once()->andThrow(
        new FalhaConexaoCliente('ERRO_MYSQL', 'Não foi possível entrar. '.SENHA_DO_FIXTURE_DETALHE, 'detalhe '.SENHA_DO_FIXTURE_DETALHE, [
            'etapa' => 'login', 'host' => '10.8.0.9', 'porta' => 3306, 'usuario' => 'root',
            'erros' => [['banco' => 'actus1', 'classe' => PDOException::class, 'codigo' => 1045, 'mensagem' => 'Access denied com '.SENHA_DO_FIXTURE_DETALHE]],
        ]),
    );

    app(RoboContingencias::class)->coletarCliente($cliente);

    $coleta = ColetaContingencias::query()->sole();
    $detalhe = $coleta->detalhe_tecnico;
    expect($coleta->erro)->toBe('Não foi possível acessar o banco do servidor do cliente.')
        ->and($detalhe['codigo'])->toBe('ERRO_MYSQL')
        ->and($detalhe['classe'])->toContain('FalhaConexaoCliente')
        ->and($detalhe['tentativa']['etapa'])->toBe('login')
        ->and($detalhe['tentativa']['host'])->toBe('10.8.0.9')
        ->and($detalhe['tentativa']['porta'])->toBe(3306)
        ->and($detalhe['tentativa']['usuario'])->toBe('root')
        ->and($detalhe['origem'])->toContain(':')
        ->and($detalhe['anteriores'])->toBe([]);
    expect(json_encode($detalhe))->not->toContain(SENHA_DO_FIXTURE_DETALHE)->and($detalhe['mensagem'])->toContain('***');
    expect(Auditoria::query()->get()->toJson())->not->toContain(SENHA_DO_FIXTURE_DETALHE)->not->toContain('login');
    Log::shouldNotHaveReceived('error');
});

test('o conector registra etapa, host, porta e erro do socket sem a senha quando a porta não abre', function () {
    config(['services.clientes.docker_hosts' => ['127.0.0.1'], 'services.clientes.docker_senha_root' => SENHA_DO_FIXTURE_DETALHE, 'services.clientes.timeout' => 2]);
    $servidor = ['pasta_multiempresa' => 'N', 'local_servidor' => 'DOCKER', 'servidor_docker' => '127.0.0.1', 'porta_mysql_docker' => '1'];

    try {
        app(ConectorDeCliente::class)->conectar(5, $servidor);
        $falha = null;
    } catch (FalhaConexaoCliente $falha) {
    }

    expect($falha)->not->toBeNull()
        ->and($falha->tentativa)->toMatchArray(['etapa' => 'porta', 'host' => '127.0.0.1', 'porta' => 1, 'usuario' => 'root', 'banco_cadastro' => 'actus5', 'local_servidor' => 'DOCKER'])
        ->and($falha->tentativa['socket'])->toHaveKeys(['codigo', 'mensagem'])
        ->and(json_encode($falha->tentativa))->not->toContain(SENHA_DO_FIXTURE_DETALHE);
});

test('falha esperada não gera erro no log, mas exceção inesperada continua gerando', function () {
    $esperado = criarClienteHelpDesk(['consultoria' => 'S']);
    Exceptions::fake();
    servidorDoClienteForaDoAr();

    app(RoboContingencias::class)->coletarCliente($esperado);

    Exceptions::assertNothingReported();
    expect(ColetaContingencias::query()->sole()->detalhe_tecnico['codigo'])->toBe('ERRO_HOST');
});

test('exceção inesperada continua indo para o relatório de erros e grava o detalhe', function () {
    $cliente = criarClienteHelpDesk(['consultoria' => 'S']);
    Exceptions::fake();
    $this->mock(ConectorDeCliente::class)->shouldReceive('conectar')->once()->andThrow(new LogicException('quebrou', 0, new RuntimeException('causa')));

    app(RoboContingencias::class)->coletarCliente($cliente);

    Exceptions::assertReportedCount(1);
    $detalhe = ColetaContingencias::query()->sole()->detalhe_tecnico;
    expect($detalhe['classe'])->toBe(LogicException::class)->and($detalhe['mensagem'])->not->toContain('quebrou')->and($detalhe['codigo'])->toBeNull()
        ->and($detalhe['anteriores'])->toHaveCount(1)->and($detalhe['anteriores'][0]['classe'])->toBe(RuntimeException::class)
        ->and(json_encode($detalhe))->not->toContain('causa');
});

test('erro de banco inesperado não deixa SQL nem binding no detalhe técnico', function () {
    $cliente = criarClienteHelpDesk(['consultoria' => 'S']);
    $chave = '52261112345678000199650010000000011000000019';
    $erro = new QueryException('mysql', 'select * from sale where chave_acesso_nfce = ?', [$chave], new PDOException('SQLSTATE[42S02]: Base table or view not found'));
    $this->mock(ConectorDeCliente::class)->shouldReceive('conectar')->once()->andThrow(new LogicException('x', 0, $erro));

    app(RoboContingencias::class)->coletarCliente($cliente);
    $bruto = json_encode(ColetaContingencias::query()->sole()->detalhe_tecnico);

    expect($bruto)->not->toContain($chave)->not->toContain('select * from sale')->not->toContain('SQL:')
        ->and($bruto)->toContain('QueryException')->toContain('SQLSTATE[42S02]');
});

test('a poda só protege o último sucesso, apaga em andamento órfã e mantém a recente', function () {
    $this->travelTo(now()->setDate(2026, 11, 5)->setTime(12, 0));
    $cliente = criarClienteHelpDesk(['consultoria' => 'S']);
    $sucesso = coletaParaRetencao($cliente, 'sucesso');
    $antigoSucesso = coletaParaRetencao($cliente, 'sucesso');
    DB::table('contingencias_coletas')->where('id', $antigoSucesso)->update(['iniciado_em' => now()->subDay()]);
    $recente = coletaParaRetencao($cliente, 'em_andamento');
    $orfa = coletaParaRetencao($cliente, 'em_andamento');
    DB::table('contingencias_coletas')->where('id', $orfa)->update(['iniciado_em' => now()->subSeconds(RoboContingencias::TRAVA_SEGUNDOS + 60)]);
    servidorDoClienteForaDoAr();

    app(RoboContingencias::class)->coletarCliente($cliente);

    $ids = DB::table('contingencias_coletas')->where('fk_c_cliente', $cliente)->pluck('id')->all();
    expect($ids)->toContain($antigoSucesso)->toContain($recente)->not->toContain($orfa)->not->toContain($sucesso);
});

test('retenção guarda as 10 últimas falhas e o último sucesso, sem tocar em outro cliente', function () {
    $cliente = criarClienteHelpDesk(['consultoria' => 'S']);
    $outro = criarClienteHelpDesk(['consultoria' => 'S']);
    $falhas = [];

    for ($i = 0; $i < 14; $i++) {
        $falhas[] = coletaParaRetencao($cliente, 'falha');

        if ($i < 3) {
            coletaParaRetencao($cliente, 'sucesso');
        }
    }

    for ($i = 0; $i < 12; $i++) {
        coletaParaRetencao($outro, 'falha');
    }
    $ciclosAntes = DB::table('contingencias_ciclos')->count();

    servidorDoClienteForaDoAr();
    app(RoboContingencias::class)->coletarCliente($cliente);

    $do = DB::table('contingencias_coletas')->where('fk_c_cliente', $cliente);
    expect((clone $do)->where('status', 'falha')->count())->toBe(10)
        ->and((clone $do)->where('status', 'sucesso')->count())->toBe(1)
        ->and((clone $do)->where('status', 'falha')->min('id'))->toBeGreaterThan($falhas[4])
        ->and(DB::table('contingencias_coletas')->where('fk_c_cliente', $outro)->count())->toBe(12);
    expect(DB::table('contingencias_ciclos')->count())->toBeGreaterThanOrEqual($ciclosAntes);
});

test('falha na limpeza não derruba a coleta', function () {
    $cliente = criarClienteHelpDesk(['consultoria' => 'S']);
    criarContingenciaSactus();
    servidorDoClienteNoBancoDeTestes();
    Log::spy();
    DB::listen(function ($consulta): void {
        if (str_starts_with($consulta->sql, 'delete from `contingencias_coletas`')) {
            throw new RuntimeException('limpeza quebrou');
        }
    });

    app(RoboContingencias::class)->coletarCliente($cliente);

    expect(ColetaContingencias::query()->sole()->status)->toBe('sucesso');
    Log::shouldHaveReceived('warning')->once();
});
