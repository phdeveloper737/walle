<?php

use App\CleanTax\RoboCleanTax;
use App\Jobs\RodarCicloCleanTax;
use App\Models\Auditoria;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/** @return array<string, string|int> */
function clienteDaApiCleanTax(int $id, string $servidor = '10.8.0.7', string $porta = '8080', string $empresa = 'Loja'): array
{
    return [
        'fk_c_cliente' => $id, 'empresa' => $empresa.$id, 'cnpj' => '11222333000181', 'servidor' => $servidor,
        'porta_http' => $porta, 'banco_dados' => 'db'.$id, 'loja' => '1', 'auditar_nfe' => 'S',
    ];
}

/** @param  list<array<string, mixed>>  $clientes */
function respostaDaListaCleanTax(array $clientes): mixed
{
    return Http::response(['status' => true, 'message' => $clientes], 200);
}

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 27)->setTime(10, 0));
    config(['walle.cleantax.ativo' => true]);
    Http::preventStrayRequests();
    Sleep::fake();
});

test('dois clientes no mesmo host nunca rodam juntos e o painel registra inicio e fim de cada requisicao', function () {
    $ativas = 0;
    $maximo = 0;
    $travaLivre = [];
    $teste = $this;

    Http::fake([
        'https://jmsistemas.net/cleantax' => respostaDaListaCleanTax([clienteDaApiCleanTax(1), clienteDaApiCleanTax(2)]),
        'http://10.8.0.7:8080/*' => function (Request $r) use (&$ativas, &$maximo, &$travaLivre, $teste) {
            $ativas++;
            $maximo = max($maximo, $ativas);
            $travaLivre[] = Cache::lock('cleantax:host:10.8.0.7', 5)->get();
            $teste->travelTo(now()->addSeconds(7));
            $ativas--;

            return Http::response('processando... FINALIZADO', 200);
        },
    ]);

    app(RoboCleanTax::class)->rodarCiclo();

    expect($maximo)->toBe(1)->and($travaLivre)->toBe([false, false]);
    $um = DB::table('painel_cleantax_worker')->where('fk_c_cliente', 1)->sole();
    $dois = DB::table('painel_cleantax_worker')->where('fk_c_cliente', 2)->sole();
    expect($um)->toMatchObject(['status_execucao' => 'success', 'http_code' => 200, 'curl_errno' => 0, 'mensagem' => 'FINALIZADO encontrado', 'servidor' => '10.8.0.7', 'porta_http' => '8080', 'banco_dados' => 'db1']);
    expect($um->data_inicio)->toBe('2026-10-27 10:00:00')->and($um->data_fim)->toBe('2026-10-27 10:00:07')
        ->and((float) $um->tempo_execucao_segundos)->toBe(7.0);
    expect($dois->data_inicio)->toBe('2026-10-27 10:00:07')->and($dois->data_fim)->toBe('2026-10-27 10:00:14');
    expect($um->url)->toContain('http://10.8.0.7:8080/sistema/app/auditoria_produto/View/cleantaxbot.php?loja=1&dbcliente=db1&auditar_nfe=S');
    expect(Auditoria::query()->where('acao', 'cleantax.auditar')->count())->toBe(2);
    expect(Cache::lock('cleantax:host:10.8.0.7', 5)->get())->toBeTrue();
});

test('API do CleanTax fora do ar encerra o ciclo com erro registrado e sem mexer no painel', function () {
    DB::table('painel_cleantax_worker')->insert(['fk_c_cliente' => 9, 'status_execucao' => 'error', 'http_code' => 500]);
    $chamadas = 0;
    Http::fake(['https://jmsistemas.net/cleantax' => function () use (&$chamadas) {
        $chamadas++;

        throw new ConnectionException('cURL error 28: timeout');
    }]);

    app(RoboCleanTax::class)->rodarCiclo();

    expect($chamadas)->toBe(3);
    expect(DB::table('painel_cleantax_worker')->where('fk_c_cliente', 9)->sole())->toMatchObject(['status_execucao' => 'error', 'http_code' => 500]);
    expect(DB::table('painel_cleantax_worker')->count())->toBe(1);
    $erro = Auditoria::query()->where('acao', 'cleantax.api_falhou')->sole();
    expect($erro->detalhes)->toMatchArray(['origem' => 'automatica', 'robo' => 'cleantax']);
});

test('robo desligado nao roda nem chama nada', function () {
    config(['walle.cleantax.ativo' => null]);
    Http::fake();

    app(RodarCicloCleanTax::class)->handle(app(RoboCleanTax::class));

    Http::assertNothingSent();
    expect(DB::table('painel_cleantax_worker')->count())->toBe(0);
});

test('timeout no cleantaxbot nao tem retry e grava exception como o 220', function () {
    $timeouts = 0;
    Http::fake([
        'https://jmsistemas.net/cleantax' => respostaDaListaCleanTax([clienteDaApiCleanTax(1), clienteDaApiCleanTax(2, '10.8.0.8')]),
        'http://10.8.0.7:8080/*' => function () use (&$timeouts) {
            $timeouts++;

            throw new ConnectionException('cURL error 28: Operation timed out');
        },
        'http://10.8.0.8:8080/*' => Http::response('sem marcador', 200),
    ]);

    app(RoboCleanTax::class)->rodarCiclo();

    expect($timeouts)->toBe(1);
    Sleep::assertNeverSlept();
    expect(DB::table('painel_cleantax_worker')->where('fk_c_cliente', 1)->sole())
        ->toMatchObject(['status_execucao' => 'exception', 'curl_errno' => 28, 'http_code' => 0])
        ->mensagem->toStartWith('CURL ERROR 28: ');
    expect(DB::table('painel_cleantax_worker')->where('fk_c_cliente', 2)->sole())
        ->toMatchObject(['status_execucao' => 'warning', 'mensagem' => 'HTTP 200 sem FINALIZADO', 'response_preview' => 'sem marcador']);
});

test('HTTP 503 tem retry com espera de 5 segundos e HTTP 404 grava error sem retry', function () {
    Http::fake([
        'https://jmsistemas.net/cleantax' => respostaDaListaCleanTax([clienteDaApiCleanTax(1), clienteDaApiCleanTax(2, '10.8.0.8')]),
        'http://10.8.0.7:8080/*' => Http::sequence()->push('x', 503)->push('FINALIZADO', 200),
        'http://10.8.0.8:8080/*' => Http::response('nada', 404),
    ]);

    app(RoboCleanTax::class)->rodarCiclo();

    Http::assertSentCount(4);
    Sleep::assertSlept(fn ($d) => (int) $d->totalSeconds === 5, 1);
    expect(DB::table('painel_cleantax_worker')->where('fk_c_cliente', 1)->value('status_execucao'))->toBe('success');
    expect(DB::table('painel_cleantax_worker')->where('fk_c_cliente', 2)->sole())->toMatchObject(['status_execucao' => 'error', 'mensagem' => 'HTTP 404']);
});

test('cliente sem servidor ou porta grava invalid_url e o ciclo segue', function () {
    Http::fake([
        'https://jmsistemas.net/cleantax' => respostaDaListaCleanTax([clienteDaApiCleanTax(1, '10.8.0.7', ''), clienteDaApiCleanTax(2, '10.8.0.8')]),
        'http://10.8.0.8:8080/*' => Http::response('FINALIZADO', 200),
    ]);

    app(RoboCleanTax::class)->rodarCiclo();

    expect(DB::table('painel_cleantax_worker')->where('fk_c_cliente', 1)->sole())
        ->toMatchObject(['status_execucao' => 'invalid_url', 'mensagem' => 'Dados invalidos para montar URL. servidor/porta_http ausente.']);
    expect(DB::table('painel_cleantax_worker')->where('fk_c_cliente', 2)->value('status_execucao'))->toBe('success');
});

test('no inicio do ciclo o painel antigo volta para success antes das novas gravacoes', function () {
    DB::table('painel_cleantax_worker')->insert(['fk_c_cliente' => 9, 'status_execucao' => 'error', 'http_code' => 500]);
    Http::fake(['https://jmsistemas.net/cleantax' => respostaDaListaCleanTax([clienteDaApiCleanTax(1)]), 'http://10.8.0.7:8080/*' => Http::response('FINALIZADO', 200)]);

    app(RoboCleanTax::class)->rodarCiclo();

    expect(DB::table('painel_cleantax_worker')->where('fk_c_cliente', 9)->sole())->toMatchObject(['status_execucao' => 'success', 'http_code' => 200]);
});

test('o Job e unico, usa a conexao robos e respeita o robo ligado', function () {
    $job = new RodarCicloCleanTax;

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)->and($job->connection)->toBe('robos')->and($job->queue)->toBe('cleantax');
});
