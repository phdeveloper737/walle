<?php

use App\Autorizadas\AnaliseAutorizadas;
use App\Autorizadas\Periodo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    // Quarta-feira; a retenção de 73 dias corta em 09/08/2026 (domingo)
    $this->travelTo(Carbon::parse('2026-10-21 10:00:00'));
});

/** @param array<string, mixed> $dados */
function chaveNaAuth(string $chave, array $dados = []): void
{
    DB::table('history_contingencia_auth')->insert(array_merge([
        'fk_c_cliente' => 1, 'chave' => $chave, 'autorizada' => 'N', 'referencia' => '2026-10-20', 'data_consulta_sefaz_bot' => null,
    ], $dados));
}

test('situação geral: a fila conta só o que o robô vai consultar', function () {
    chaveNaAuth(chaveValida(1), ['autorizada' => 'S', 'data_consulta_sefaz_bot' => '2026-10-21 08:00:00']);
    chaveNaAuth(chaveValida(2));
    chaveNaAuth(chaveValida(3), ['data_consulta_sefaz_bot' => '2026-10-21 09:30:00']);
    chaveNaAuth(chaveValida(4, uf: '51'), ['fk_c_cliente' => 2]);

    $geral = app(AnaliseAutorizadas::class)->geral();

    expect($geral)->toMatchArray([
        'total' => 4, 'autorizadas' => 1, 'pendentes' => 3,
        'fila' => 1, 'outras_uf' => 1, 'nunca' => 2,
        'clientes' => 2, 'hoje' => 2, 'hora' => 1, 'taxa' => 25.0,
    ])->and($geral['ultima']->toDateTimeString())->toBe('2026-10-21 09:30:00');
});

test('semanas de segunda a domingo cobrindo a retenção, sem buraco', function () {
    chaveNaAuth(chaveValida(1), ['referencia' => '2026-10-19', 'autorizada' => 'S']);
    chaveNaAuth(chaveValida(2), ['referencia' => '2026-10-21']);
    chaveNaAuth(chaveValida(3), ['referencia' => '2026-10-12', 'autorizada' => 'S']);

    $semanas = app(AnaliseAutorizadas::class)->semanas();

    expect($semanas)->toHaveCount(12)
        ->and($semanas[0]['inicio'])->toBe('2026-08-03')
        ->and($semanas[10])->toMatchArray(['rotulo' => '12/10', 'total' => 1, 'autorizadas' => 1, 'taxa' => 100.0])
        ->and($semanas[11])->toMatchArray(['rotulo' => '19/10', 'total' => 2, 'autorizadas' => 1, 'pendentes' => 1, 'taxa' => 50.0])
        ->and($semanas[5]['total'])->toBe(0);
});

test('por dia: mês atual até hoje e o anterior inteiro, alinhados pelo dia', function () {
    chaveNaAuth(chaveValida(1), ['referencia' => '2026-10-21']);
    chaveNaAuth(chaveValida(2), ['referencia' => '2026-10-21']);
    chaveNaAuth(chaveValida(3), ['referencia' => '2026-09-30']);

    $diario = app(AnaliseAutorizadas::class)->diario();

    expect($diario['dias'])->toHaveCount(31)
        ->and($diario['atual'][20])->toBe(2)
        ->and($diario['atual'][21])->toBeNull()
        ->and($diario['anterior'][29])->toBe(1)
        ->and($diario['anterior'][30])->toBeNull()
        ->and($diario['rotulo_atual'])->toBe('Outubro')
        ->and($diario['rotulo_anterior'])->toBe('Setembro');
});

test('recortes do período: resumo, ranking, perfil e tempo até autorizar', function () {
    chaveNaAuth(chaveValida(1), ['fk_c_cliente' => 7, 'autorizada' => 'S', 'referencia' => '2026-10-10', 'data_consulta_sefaz_bot' => '2026-10-10 18:00:00']);
    chaveNaAuth(chaveValida(2), ['fk_c_cliente' => 7, 'autorizada' => 'S', 'referencia' => '2026-10-01', 'data_consulta_sefaz_bot' => '2026-10-05 09:00:00']);
    chaveNaAuth(chaveValida(3), ['fk_c_cliente' => 7, 'referencia' => '2026-10-02']);
    chaveNaAuth(chaveValida(4, uf: '51'), ['fk_c_cliente' => 8, 'autorizada' => 'S', 'referencia' => '2026-10-02', 'data_consulta_sefaz_bot' => '2026-10-20 09:00:00']);
    chaveNaAuth(chaveValida(5), ['fk_c_cliente' => 9, 'referencia' => '2026-09-15']);

    $analise = app(AnaliseAutorizadas::class);
    $outubro = Periodo::doAtalho('mes', '2026-08-09');

    expect($analise->periodo($outubro))->toBe(['total' => 4, 'autorizadas' => 3, 'pendentes' => 1, 'nunca' => 1, 'clientes' => 2, 'cnpjs' => 1, 'taxa' => 75.0])
        ->and($analise->clientes($outubro))->toBe([
            ['cliente' => 7, 'total' => 3, 'autorizadas' => 2, 'pendentes' => 1, 'taxa' => 66.7],
            ['cliente' => 8, 'total' => 1, 'autorizadas' => 1, 'pendentes' => 0, 'taxa' => 100.0],
        ]);

    $perfil = $analise->perfil($outubro);
    expect($perfil['ufs'])->toBe([
        ['rotulo' => 'GO', 'total' => 3, 'autorizadas' => 2, 'taxa' => 66.7],
        ['rotulo' => 'MT', 'total' => 1, 'autorizadas' => 1, 'taxa' => 100.0],
    ])->and($perfil['modelos'][0]['rotulo'])->toBe('NFC-e (65)')
        ->and($perfil['emissoes'][0]['rotulo'])->toBe('Off-line NFC-e');

    $latencia = $analise->latencia($outubro);
    expect(array_column($latencia['faixas'], 'total'))->toBe([1, 0, 1, 1, 0])
        ->and($latencia['maior'])->toBe(18)
        ->and($latencia['total'])->toBe(3);
});

test('pendentes mais antigas primeiro, com filtro por cliente', function () {
    chaveNaAuth(chaveValida(1), ['fk_c_cliente' => 7, 'referencia' => '2026-10-01']);
    chaveNaAuth(chaveValida(2), ['fk_c_cliente' => 8, 'referencia' => '2026-09-01']);
    chaveNaAuth(chaveValida(3), ['fk_c_cliente' => 7, 'referencia' => '2026-08-20', 'autorizada' => 'S']);

    $analise = app(AnaliseAutorizadas::class);

    expect(array_column($analise->pendentesAntigas(), 'chave'))->toBe([chaveValida(2), chaveValida(1)])
        ->and($analise->pendentesAntigas()[0]['dias'])->toBe(50)
        ->and(array_column($analise->pendentesAntigas(7), 'chave'))->toBe([chaveValida(1)]);
});

test('atalhos de período', function () {
    $corte = '2026-08-09';

    expect(Periodo::doAtalho('hoje', $corte))->toEqual(new Periodo('2026-10-21', '2026-10-21', 'Hoje'))
        ->and(Periodo::doAtalho('7d', $corte)->de)->toBe('2026-10-15')
        ->and(Periodo::doAtalho('mes_anterior', $corte))->toEqual(new Periodo('2026-09-01', '2026-09-30', 'Mês anterior'))
        ->and(Periodo::doAtalho('tudo', $corte)->de)->toBe($corte)
        ->and(Periodo::doAtalho('qualquer coisa', $corte)->rotulo)->toBe('Este mês');
});
