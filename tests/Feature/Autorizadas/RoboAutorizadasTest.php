<?php

use App\Autorizadas\RoboAutorizadas;
use App\Jobs\RodarCicloAutorizadas;
use App\Models\CicloAutorizadas;
use App\Sefaz\ConsultaSituacao;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    // As chaves de teste são de out/2026: com a data fixa, a retenção (73 dias) não as apaga com o tempo
    $this->travelTo(Carbon::parse('2026-10-21 10:00:00'));
    config(['walle.autorizadas.pausa_entre_chaves' => 0]);
});

function situacaoNaAuth(string $chave): ?object
{
    return DB::table('history_contingencia_auth')->where('chave', $chave)->first();
}

test('semeia as chaves novas do histórico, consulta na Sefaz e grava o resultado', function () {
    [$autorizada, $inexistente] = [chaveValida(1), chaveValida(2)];
    criarHistoricoContingencia(10, $autorizada, '2026-10-15 09:00:00');
    criarHistoricoContingencia(10, $inexistente, null);
    $sefaz = sefazFalsa([$autorizada => '100']);

    $ciclo = app(RoboAutorizadas::class)->rodarCiclo();

    expect($ciclo->only(['semeadas', 'na_fila', 'consultadas', 'autorizadas', 'gravadas', 'falhas']))
        ->toBe(['semeadas' => 2, 'na_fila' => 2, 'consultadas' => 2, 'autorizadas' => 1, 'gravadas' => 2, 'falhas' => 0])
        ->and($ciclo->terminado_em)->not->toBeNull()
        ->and($sefaz->consultadas)->toEqualCanonicalizing([$autorizada, $inexistente]);

    expect(situacaoNaAuth($autorizada))
        ->autorizada->toBe('S')
        ->referencia->toBe('2026-10-15')
        ->data_consulta_sefaz_bot->not->toBeNull();

    // Sem data fiscal, a referência sai do mês da própria chave
    expect(situacaoNaAuth($inexistente))
        ->autorizada->toBe('N')
        ->referencia->toBe('2026-10-01');
});

test('consultada há menos de 3 horas não volta para a fila; há mais, volta', function () {
    [$recente, $antiga] = [chaveValida(3), chaveValida(4)];
    DB::table('history_contingencia_auth')->insert([
        ['fk_c_cliente' => 1, 'chave' => $recente, 'autorizada' => 'N', 'referencia' => '2026-10-01', 'data_consulta_sefaz_bot' => now()->subHour()],
        ['fk_c_cliente' => 1, 'chave' => $antiga, 'autorizada' => 'N', 'referencia' => '2026-10-01', 'data_consulta_sefaz_bot' => now()->subHours(4)],
    ]);
    $sefaz = sefazFalsa();

    app(RoboAutorizadas::class)->rodarCiclo();

    expect($sefaz->consultadas)->toBe([$antiga]);
});

test('só consulta chaves de GO e nunca as já autorizadas', function () {
    $outraUf = chaveValida(5, uf: '35');
    $jaAutorizada = chaveValida(6);
    criarHistoricoContingencia(1, $outraUf);
    DB::table('history_contingencia_auth')->insert(['fk_c_cliente' => 1, 'chave' => $jaAutorizada, 'autorizada' => 'S', 'referencia' => '2026-10-01']);
    $sefaz = sefazFalsa();

    app(RoboAutorizadas::class)->rodarCiclo();

    expect($sefaz->consultadas)->toBe([])
        ->and(situacaoNaAuth($outraUf)->autorizada)->toBe('N');
});

test('apaga as chaves que passaram da retenção de 73 dias', function () {
    DB::table('history_contingencia_auth')->insert([
        ['fk_c_cliente' => 1, 'chave' => chaveValida(7), 'autorizada' => 'S', 'referencia' => now()->subDays(100)->toDateString()],
        ['fk_c_cliente' => 1, 'chave' => chaveValida(8), 'autorizada' => 'S', 'referencia' => now()->subDays(10)->toDateString()],
    ]);
    sefazFalsa();

    $ciclo = app(RoboAutorizadas::class)->rodarCiclo();

    expect($ciclo->removidas)->toBe(1)
        ->and(situacaoNaAuth(chaveValida(7)))->toBeNull()
        ->and(situacaoNaAuth(chaveValida(8)))->not->toBeNull();
});

test('não semeia de novo o que já passou da retenção (o antigo apagava e consultava de novo a cada volta)', function () {
    criarHistoricoContingencia(1, chaveValida(9), '2026-05-02 10:00:00');
    $sefaz = sefazFalsa();

    $ciclo = app(RoboAutorizadas::class)->rodarCiclo();

    expect($ciclo->semeadas)->toBe(0)
        ->and($sefaz->consultadas)->toBe([])
        ->and(situacaoNaAuth(chaveValida(9)))->toBeNull();
});

test('no consumo indevido para o ciclo e pausa o robô', function () {
    [$a, $b] = [chaveValida(20), chaveValida(21)];
    DB::table('history_contingencia_auth')->insert([
        ['fk_c_cliente' => 1, 'chave' => $a, 'autorizada' => 'N', 'referencia' => '2026-10-01', 'data_consulta_sefaz_bot' => null],
        ['fk_c_cliente' => 1, 'chave' => $b, 'autorizada' => 'N', 'referencia' => '2026-10-01', 'data_consulta_sefaz_bot' => now()->subDay()],
    ]);
    $sefaz = sefazFalsa([$a => '656']);
    $robo = app(RoboAutorizadas::class);

    $ciclo = $robo->rodarCiclo();

    expect($sefaz->consultadas)->toBe([$a])
        ->and($ciclo->consumo_indevido)->toBeTrue()
        ->and($ciclo->falhas)->toBe(1)
        ->and($robo->pausa())
        ->automatica->toBeTrue()
        ->motivo->toBe("A Sefaz acusou consumo indevido (656) no ciclo #{$ciclo->id}")
        ->and($robo->pausadoAte()->toDateTimeString())->toBe('2026-10-21 11:00:00');
});

test('erro inesperado fica registrado no ciclo', function () {
    criarHistoricoContingencia(1, chaveValida(30));
    app()->instance(ConsultaSituacao::class, new ConsultaSituacao(fn () => throw new RuntimeException('certificado sumiu')));

    $ciclo = app(RoboAutorizadas::class)->rodarCiclo();

    expect($ciclo->erro)->toBe('certificado sumiu')
        ->and($ciclo->terminado_em)->not->toBeNull();
});

test('o job só roda o ciclo com o robô ligado e sem pausa', function () {
    sefazFalsa();
    $robo = app(RoboAutorizadas::class);

    config(['walle.autorizadas.ativo' => false]);
    (new RodarCicloAutorizadas)->handle($robo);
    expect(CicloAutorizadas::count())->toBe(0);

    config(['walle.autorizadas.ativo' => true]);
    $robo->pausar(now()->addMinutes(10));
    (new RodarCicloAutorizadas)->handle($robo);
    expect(CicloAutorizadas::count())->toBe(0);

    $robo->retomar();
    (new RodarCicloAutorizadas)->handle($robo);
    expect(CicloAutorizadas::count())->toBe(1);
});
