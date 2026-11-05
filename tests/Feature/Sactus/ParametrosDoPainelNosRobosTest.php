<?php

use App\Autorizadas\RoboAutorizadas;
use App\CleanTax\RoboCleanTax;
use App\Contingencias\Cliente\ConectorDeCliente;
use App\Contingencias\Cliente\ConexaoComCliente;
use App\Contingencias\RoboContingencias;
use App\Models\CicloSactus;
use App\Sactus\RoboSactus;
use App\Sactus\Ssh\ResultadoComando;
use App\Sefaz\ConsultaSituacao;
use App\Sentinela\RoboSentinela;
use App\Support\ConfiguracaoRobo;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\SessaoSefazFalsa;

/*
| Task 5.2: os robôs leem os parâmetros configurados no painel (robo_config) e a mudança feita no meio
| de um ciclo só vale no ciclo seguinte (ConfiguracaoRobo::noCiclo congela os valores no início).
| Os parâmetros de Tarefas (hora_inicio e hora_fim) são provados em Ferramentas/RoboTarefasTest.
*/

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-21 10:00:00'));
});

/**
 * Sefaz falsa que avisa a cada consulta (para o teste mexer na configuração no meio do ciclo).
 *
 * @param  array<string, string>  $cStatPorChave
 */
function sefazFalsaComGanchoDeParametros(array $cStatPorChave, Closure $aoConsultar): SessaoSefazFalsa
{
    $sessao = new class($cStatPorChave, $aoConsultar) extends SessaoSefazFalsa
    {
        /** @param array<string, string> $cStatPorChave */
        public function __construct(array $cStatPorChave, private readonly Closure $aoConsultar)
        {
            parent::__construct($cStatPorChave);
        }

        public function enviar(string $envelope): array
        {
            ($this->aoConsultar)();

            return parent::enviar($envelope);
        }
    };
    app()->instance(ConsultaSituacao::class, new ConsultaSituacao(fn () => $sessao));

    return $sessao;
}

function ligarRoboParaParametrosDoPainel(string $robo): void
{
    config(["walle.{$robo}.ativo" => true]);
    ConfiguracaoRobo::definirAtivo($robo, true, 'teste');
}

test('Autorizadas usa o intervalo_horas configurado no painel', function () {
    ligarRoboParaParametrosDoPainel('autorizadas');
    ConfiguracaoRobo::gravar('autorizadas', 'pausa_entre_chaves', 0, 'teste');
    $chave = chaveValida(41);
    DB::table('history_contingencia_auth')->insert([
        'fk_c_cliente' => 1, 'chave' => $chave, 'autorizada' => 'N', 'referencia' => '2026-10-01', 'data_consulta_sefaz_bot' => now()->subHours(2),
    ]);
    $sefaz = sefazFalsa();

    app(RoboAutorizadas::class)->rodarCiclo();
    expect($sefaz->consultadas)->toBe([], 'com o padrão de 3 h a chave consultada há 2 h não volta para a fila');

    ConfiguracaoRobo::gravar('autorizadas', 'intervalo_horas', 1, 'teste');
    app(RoboAutorizadas::class)->rodarCiclo();

    expect($sefaz->consultadas)->toBe([$chave]);
});

test('Autorizadas: parâmetro alterado durante o ciclo só vale no ciclo seguinte', function () {
    ligarRoboParaParametrosDoPainel('autorizadas');
    ConfiguracaoRobo::gravar('autorizadas', 'pausa_entre_chaves', 0, 'teste');
    $chave = chaveValida(42);
    DB::table('history_contingencia_auth')->insert([
        'fk_c_cliente' => 1, 'chave' => $chave, 'autorizada' => 'N', 'referencia' => '2026-10-01', 'data_consulta_sefaz_bot' => null,
    ]);
    $alterado = false;
    sefazFalsaComGanchoDeParametros([$chave => '656'], function () use (&$alterado): void {
        if (! $alterado) {
            $alterado = true;
            ConfiguracaoRobo::gravar('autorizadas', 'pausa_consumo_indevido_minutos', 5, 'teste');
        }
    });
    $robo = app(RoboAutorizadas::class);

    $robo->rodarCiclo();

    expect($alterado)->toBeTrue()
        ->and($robo->pausadoAte()->toDateTimeString())->toBe('2026-10-21 11:00:00', 'o ciclo que já estava rodando usou os 60 min congelados');

    $robo->retomar();
    $this->travelTo(now()->addHours(4));
    $robo->rodarCiclo();

    expect($robo->pausadoAte()->toDateTimeString())->toBe('2026-10-21 14:05:00', 'o ciclo seguinte usa os 5 min gravados');
});

test('CleanTax usa os hosts_seriais configurados no painel', function () {
    ligarRoboParaParametrosDoPainel('cleantax');
    Http::preventStrayRequests();
    Sleep::fake();
    $cliente = fn (int $id): array => [
        'fk_c_cliente' => $id, 'empresa' => 'Loja'.$id, 'cnpj' => '11222333000181', 'servidor' => '10.8.0.7',
        'porta_http' => '8080', 'banco_dados' => 'db'.$id, 'loja' => '1', 'auditar_nfe' => 'S',
    ];
    Http::fake([
        'https://jmsistemas.net/cleantax' => Http::response(['status' => true, 'message' => [$cliente(1), $cliente(2)]], 200),
        'http://10.8.0.7:8080/*' => Http::response('FINALIZADO', 200),
    ]);

    app(RoboCleanTax::class)->rodarCiclo();
    Sleep::assertNeverSlept();

    ConfiguracaoRobo::gravar('cleantax', 'hosts_seriais', ['10.8.0.7'], 'teste');
    app(RoboCleanTax::class)->rodarCiclo();

    Sleep::assertSlept(fn ($duracao): bool => $duracao->totalMilliseconds > 0 && $duracao->totalMilliseconds <= 3000, 1);
});

test('Sactus usa limite_ciclo_minutos do painel e só o ciclo seguinte enxerga a mudança feita no meio do ciclo', function () {
    ligarRoboParaParametrosDoPainel('sactus');
    config(['walle.sactus.erp.senha' => 'senha-do-220']);
    criarClienteSactus(['ip_externo_servidor' => '200.1.2.3']);
    criarClienteSactus(['ip_externo_servidor' => '200.1.2.4']);
    $gravou = false;
    $ssh = sshFalso();
    foreach (['200.1.2.3', '200.1.2.4'] as $host) {
        $ssh->servidor($host, 22, 'senha-ssh', ['/etc/sactus.cfg' => cfgSactus()]);
    }
    // No primeiro cliente: muda o limite no meio do ciclo e deixa passar 2 minutos
    $ssh->regra('200.1.2.3', 22, function (string $comando) use (&$gravou): ?ResultadoComando {
        if (str_starts_with($comando, 'cat ')) {
            if (! $gravou) {
                $gravou = true;
                ConfiguracaoRobo::gravar('sactus', 'limite_ciclo_minutos', 1, 'teste');
            }
            $this->travelTo(now()->addMinutes(2));
        }

        return null;
    });

    $primeiroCiclo = app(RoboSactus::class)->rodarCiclo();

    expect($primeiroCiclo->only(['coletados', 'erro']))->toBe(['coletados' => 2, 'erro' => null], 'o ciclo em andamento seguiu com os 55 min congelados');

    $segundoCiclo = app(RoboSactus::class)->rodarCiclo();

    expect($segundoCiclo->coletados)->toBe(1)
        ->and($segundoCiclo->erro)->toBe('Ciclo interrompido no limite de 1 minutos', 'o ciclo seguinte usa o limite gravado no painel')
        ->and(CicloSactus::count())->toBe(2);
});

test('Sactus usa as regras de ACL configuradas no painel', function () {
    ligarRoboParaParametrosDoPainel('sactus');
    config(['walle.sactus.erp.senha' => 'senha-do-220']);
    criarClienteSactus(['service_acl' => 'S']);
    $ssh = sshFalso()->servidor('200.1.2.3', 22, 'senha-ssh', ['/etc/sactus.cfg' => cfgSactus(), '/opt/outro.ini' => 'x=1']);
    ConfiguracaoRobo::gravar('sactus', 'acl.regras', ['/opt/outro.ini' => 'operador'], 'teste');

    app(RoboSactus::class)->rodarCiclo();

    $comandos = implode("\n", $ssh->comandosEm('200.1.2.3', 22));
    expect($comandos)->toContain('/opt/outro.ini')->not->toContain('/var/sistema/config.ini');
});

test('Sactus não coleta o cliente listado em clientes_ignorados no painel', function () {
    ligarRoboParaParametrosDoPainel('sactus');
    config(['walle.sactus.erp.senha' => 'senha-do-220']);
    $ignorado = criarClienteSactus(['ip_externo_servidor' => '200.1.2.3']);
    criarClienteSactus(['ip_externo_servidor' => '200.1.2.4']);
    $ssh = sshFalso();
    foreach (['200.1.2.3', '200.1.2.4'] as $host) {
        $ssh->servidor($host, 22, 'senha-ssh', ['/etc/sactus.cfg' => cfgSactus()]);
    }
    ConfiguracaoRobo::gravar('sactus', 'clientes_ignorados', [$ignorado], 'teste');

    $ciclo = app(RoboSactus::class)->rodarCiclo();

    expect($ciclo->coletados)->toBe(1)
        ->and($ssh->comandosEm('200.1.2.3', 22))->toBe([])
        ->and($ssh->comandosEm('200.1.2.4', 22))->not->toBe([]);
});

test('Sentinela congela os parâmetros no ciclo e solta no fim', function () {
    Http::preventStrayRequests();
    ligarRoboParaParametrosDoPainel('sentinela');
    criarClienteHelpDesk([], ['ip_externo_servidor' => '10.8.0.5']);
    $vistos = [];
    Http::fake(['http://10.8.0.5/*' => function (Request $r) use (&$vistos) {
        $vistos[] = ConfiguracaoRobo::efetivo('sentinela', 'ativo');
        ConfiguracaoRobo::definirAtivo('sentinela', false, 'teste');
        $vistos[] = ConfiguracaoRobo::efetivo('sentinela', 'ativo');

        return Http::response(json_encode(['ok' => true]), 200);
    }]);

    app(RoboSentinela::class)->rodarCiclo();

    expect($vistos)->toBe([true, true])
        ->and(ConfiguracaoRobo::efetivo('sentinela', 'ativo'))->toBeFalse();
});

test('Contingências congela os parâmetros no ciclo e solta no fim', function () {
    ligarRoboParaParametrosDoPainel('contingencias');
    criarClienteHelpDesk(['consultoria' => 'S']);
    $vistos = [];
    $this->mock(ConectorDeCliente::class)->shouldReceive('conectar')->andReturnUsing(function () use (&$vistos) {
        $vistos[] = ConfiguracaoRobo::efetivo('contingencias', 'ativo');
        ConfiguracaoRobo::definirAtivo('contingencias', false, 'teste');
        $vistos[] = ConfiguracaoRobo::efetivo('contingencias', 'ativo');

        return new ConexaoComCliente(DB::connection(), DB::getDatabaseName(), fecharAoTerminar: false);
    });

    app(RoboContingencias::class)->rodarCiclo();

    expect($vistos)->toBe([true, true])
        ->and(ConfiguracaoRobo::efetivo('contingencias', 'ativo'))->toBeFalse();
});
