<?php

use App\Jobs\RodarCicloSentinela;
use App\Models\Auditoria;
use App\Models\PainelSentinela;
use App\Sentinela\RoboSentinela;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/** @return array<string, mixed> */
function jsonDoSentinelaDeExemplo(): array
{
    return [
        'ok' => true,
        'sistema' => ['hostname' => 'srv-loja', 'os' => 'Ubuntu 22.04', 'kernel' => '5.15.0', 'uptime' => '12 dias', 'php' => '8.1.2'],
        'cpu' => ['uso' => 12.34, 'nucleos' => 4, 'load' => [0.5, 0.75, 1.25]],
        'memoria' => ['total' => 8000, 'realUsado' => 3200, 'pct' => 40.04],
        'swap' => ['pct' => 1.5],
        'discoPrincipal' => ['mount' => '/', 'totalGb' => 100.55, 'usadoGb' => 40.25, 'pct' => 41],
        'processos' => ['total' => 210],
        'vpn' => ['ativo' => true, 'tun' => 'tun0', 'mtu' => 1500, 'inconsistente' => false],
        'sactusBin' => ['bateu' => true, 'versao' => '5.0.1'],
        'sactus' => ['ok' => true, 'rodando' => true, 'mtime' => '2026-10-27 09:59:00'],
    ];
}

function clienteDaVpnParaSentinela(string $ip = '10.8.0.5', array $cliente = [], array $servidor = []): int
{
    return criarClienteHelpDesk($cliente, ['ip_externo_servidor' => $ip, ...$servidor]);
}

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 27)->setTime(10, 0));
    config(['walle.sentinela.ativo' => true]);
    Http::preventStrayRequests();
});

test('grava no painel o que o 220 gravaria para o mesmo JSON', function () {
    $cliente = clienteDaVpnParaSentinela();
    $corpo = json_encode(jsonDoSentinelaDeExemplo());
    Http::fake(['http://10.8.0.5/sentinela.php*' => Http::response($corpo, 200)]);

    app(RoboSentinela::class)->rodarCiclo();

    Http::assertSent(fn (Request $r) => $r->url() === 'http://10.8.0.5/sentinela.php?json=S' && $r->method() === 'GET');
    $linha = DB::table('painel_sentinela')->where('fk_c_cliente', $cliente)->sole();
    expect((array) $linha)->toMatchArray([
        'status' => 'S', 'http_code' => 200, 'erro' => '', 'host' => '10.8.0.5',
        'url' => 'http://10.8.0.5/sentinela.php?json=S',
        'hostname' => 'srv-loja', 'os' => 'Ubuntu 22.04', 'kernel' => '5.15.0', 'uptime' => '12 dias', 'php' => '8.1.2',
        'cpu_uso' => '12.3', 'cpu_nucleos' => 4, 'load1' => '0.50', 'load5' => '0.75', 'load15' => '1.25',
        'mem_total_mb' => 8000, 'mem_usado_mb' => 3200, 'mem_pct' => '40.0', 'swap_pct' => '1.5',
        'disco_mount' => '/', 'disco_total_gb' => '100.6', 'disco_usado_gb' => '40.3', 'disco_pct' => 41,
        'sactus_rodando' => 'S', 'sactus_mtime' => '2026-10-27 09:59:00', 'sactus_md5_ok' => 'S', 'sactus_versao' => '5.0.1',
        'vpn_ativo' => 'S', 'vpn_tun' => 'tun0', 'vpn_mtu' => 1500, 'vpn_inconsistente' => 'N',
        'proc_total' => 210, 'data' => '2026-10-27 10:00:00',
    ]);
    expect(json_decode($linha->json, true))->toEqual(json_decode($corpo, true));

    $auditoria = Auditoria::query()->where('acao', 'sentinela.coletar')->sole();
    expect($auditoria->fk_c_cliente)->toBe($cliente)->and($auditoria->detalhes)->toMatchArray(['origem' => 'automatica', 'robo' => 'sentinela', 'status' => 'S']);
});

test('Sactus sem checagem fica X, MD5 divergente N e cpu nula vira zero como no 220', function () {
    $cliente = clienteDaVpnParaSentinela();
    $json = jsonDoSentinelaDeExemplo();
    $json['sactus'] = ['ok' => false];
    $json['sactusBin'] = ['bateu' => false];
    $json['cpu'] = ['uso' => null, 'nucleos' => 2];
    Http::fake(['*' => Http::response(json_encode($json), 200)]);

    app(RoboSentinela::class)->rodarCiclo();

    expect((array) DB::table('painel_sentinela')->where('fk_c_cliente', $cliente)->sole())->toMatchArray([
        'sactus_rodando' => 'X', 'sactus_mtime' => '', 'sactus_md5_ok' => 'N', 'cpu_uso' => '0.0', 'load1' => '0.00',
    ]);
});

test('regrava a mesma linha do cliente no ciclo seguinte', function () {
    $cliente = clienteDaVpnParaSentinela();
    Http::fake(['*' => Http::response(json_encode(jsonDoSentinelaDeExemplo()), 200)]);

    app(RoboSentinela::class)->rodarCiclo();
    app(RoboSentinela::class)->rodarCiclo();

    expect(PainelSentinela::query()->where('fk_c_cliente', $cliente)->count())->toBe(1);
});

test('timeout grava sem resposta com a mensagem e o próximo cliente é coletado', function () {
    $falha = clienteDaVpnParaSentinela('10.8.0.6');
    $ok = clienteDaVpnParaSentinela('10.8.0.7');
    Http::fake([
        'http://10.8.0.6/*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out'),
        'http://10.8.0.7/*' => Http::response(json_encode(jsonDoSentinelaDeExemplo()), 200),
    ]);

    app(RoboSentinela::class)->rodarCiclo();

    $linha = DB::table('painel_sentinela')->where('fk_c_cliente', $falha)->sole();
    expect($linha->status)->toBe('N')
        ->and($linha->erro)->toBe('10.8.0.6 => cURL error 28: Operation timed out')
        ->and($linha->host)->toBe('10.8.0.6')
        ->and($linha->url)->toBe('http://10.8.0.6/sentinela.php?json=S')
        ->and($linha->json)->toBeNull()->and($linha->cpu_uso)->toBeNull()->and($linha->sactus_rodando)->toBe('X');
    expect(DB::table('painel_sentinela')->where('fk_c_cliente', $ok)->value('status'))->toBe('S');
});

test('JSON inválido, HTTP diferente de 200 e ok=false gravam o erro do 220', function () {
    $invalido = clienteDaVpnParaSentinela('10.8.0.8');
    $erroHttp = clienteDaVpnParaSentinela('10.8.0.9');
    $semOk = clienteDaVpnParaSentinela('10.8.0.10');
    $vazio = clienteDaVpnParaSentinela('10.8.0.11');
    Http::fake([
        'http://10.8.0.8/*' => Http::response('<html>', 200),
        'http://10.8.0.9/*' => Http::response('erro', 500),
        'http://10.8.0.10/*' => Http::response(json_encode(['ok' => false, 'erro' => 'ssh falhou']), 200),
        'http://10.8.0.11/*' => Http::response('', 502),
    ]);

    app(RoboSentinela::class)->rodarCiclo();

    $erro = fn (int $id) => DB::table('painel_sentinela')->where('fk_c_cliente', $id)->first();
    expect($erro($invalido)->erro)->toBe('10.8.0.8 => resposta nao e JSON valido')
        ->and($erro($erroHttp)->erro)->toBe('10.8.0.9 => HTTP 500')->and($erro($erroHttp)->http_code)->toBe(500)
        ->and($erro($semOk)->erro)->toBe('10.8.0.10 => sentinela.php ok=false: ssh falhou')
        ->and($erro($vazio)->erro)->toBe('10.8.0.11 => sem resposta (HTTP 502)');
    expect(DB::table('painel_sentinela')->where('status', 'N')->count())->toBe(4);
});

test('cliente sem host válido grava a mensagem do 220 sem requisição', function () {
    $cliente = clienteDaVpnParaSentinela('10.8.0.12', servidor: ['servidor_docker' => '']);
    DB::connection('help')->table('informacao_tecnica_dataserver')->where('fk_c_cliente', $cliente)->update(['ip_externo_servidor' => '10.8.0.12 x']);
    Http::fake();

    app(RoboSentinela::class)->rodarCiclo();

    expect(DB::table('painel_sentinela')->where('fk_c_cliente', $cliente)->value('erro'))
        ->toBe('Nenhum host HTTP valido nas colunas: ip_externo_servidor, servidor_docker');
    Http::assertNothingSent();
});

test('só consulta clientes ativos com IP da VPN 10.8.0.', function () {
    clienteDaVpnParaSentinela('200.1.2.3');
    clienteDaVpnParaSentinela('10.8.0.20', ['deletado' => 'S']);
    $vpn = clienteDaVpnParaSentinela('10.8.0.21', servidor: ['servidor_docker' => '10.8.0.22']);
    Http::fake(['http://10.8.0.21/*' => Http::response('', 500), 'http://10.8.0.22/*' => Http::response(json_encode(jsonDoSentinelaDeExemplo()), 200)]);

    app(RoboSentinela::class)->rodarCiclo();

    expect(DB::table('painel_sentinela')->count())->toBe(1);
    $linha = DB::table('painel_sentinela')->where('fk_c_cliente', $vpn)->sole();
    expect($linha->status)->toBe('S')->and($linha->host)->toBe('10.8.0.22');
});

test('robô desligado não roda, nem pelo Job', function () {
    clienteDaVpnParaSentinela();
    config(['walle.sentinela.ativo' => null]);
    Http::fake();

    expect(app(RoboSentinela::class)->ligado())->toBeFalse();
    app(RodarCicloSentinela::class)->handle(app(RoboSentinela::class));
    app(RoboSentinela::class)->rodarCiclo();

    Http::assertNothingSent();
    expect(DB::table('painel_sentinela')->count())->toBe(0)->and(Auditoria::query()->count())->toBe(0);
});

test('o Job é único e usa a conexão robos', function () {
    $job = new RodarCicloSentinela;

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)->and($job->connection)->toBe('robos')->and($job->queue)->toBe('sentinela');
});

test('o timeout é o do 220: 35 s no total e 8 s na conexão', function () {
    clienteDaVpnParaSentinela();
    Http::fake(['*' => Http::response(json_encode(jsonDoSentinelaDeExemplo()), 200)]);

    app(RoboSentinela::class)->rodarCiclo();

    expect(RoboSentinela::TIMEOUT_SEGUNDOS)->toBe(35)->and(RoboSentinela::TIMEOUT_CONEXAO_SEGUNDOS)->toBe(8);
});
