<?php

use App\Autorizadas\DiagnosticoChave;
use App\Autorizadas\RoboAutorizadas;
use App\Jobs\RodarCicloAutorizadas;
use App\Livewire\Autorizadas\Analise;
use App\Livewire\Autorizadas\Chave;
use App\Livewire\Autorizadas\Robo;
use App\Models\Auditoria;
use App\Models\Usuario;
use App\Sefaz\ChaveDeAcesso;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-21 10:00:00'));
    config(['walle.admins' => []]);
    $this->usuario = Usuario::find(criarUsuarioHelpDesk());
    $this->actingAs($this->usuario);
});

function diagnosticoDe(string $chave): DiagnosticoChave
{
    return DiagnosticoChave::de(ChaveDeAcesso::de($chave), app(RoboAutorizadas::class));
}

test('a tela abre nas três abas', function () {
    $this->get(route('autorizadas'))->assertOk()->assertSee(['Robô', 'Análise', 'Consultar chave', 'Desligado neste servidor']);
    $this->get(route('autorizadas', ['aba' => 'chave']))->assertOk()->assertSee('Informe uma chave');
    $this->get(route('autorizadas', ['aba' => 'analise']))->assertOk()->assertSee('Somando a base das autorizadas');
});

test('a análise mostra os números do período e o nome do cliente', function () {
    $cliente = criarClienteHelpDesk(['razao_social' => 'SUPERMERCADO BOM PRECO']);
    autorizarNaSefaz($cliente, chaveValida(1), '2026-10-10');

    Livewire::withoutLazyLoading()->test(Analise::class)
        ->assertSee('Contingências por semana')
        ->assertSee('SUPERMERCADO BOM PRECO')
        ->set('periodo', 'ontem')
        ->assertSee('Nenhuma contingência no período')
        ->set('periodo', 'invalido')
        ->assertSet('periodo', 'mes');
});

test('pausar e retomar pelo painel, guardando quem pausou', function () {
    config(['walle.autorizadas.ativo' => true, 'walle.admins' => [$this->usuario->id]]);
    session()->put('walle.senha_confirmada_em', now()->getTimestamp());
    $robo = app(RoboAutorizadas::class);

    Livewire::test(Robo::class)->call('pausar', 2)->assertSet('aviso', null);
    expect($robo->pausa())->toBeNull();

    Livewire::test(Robo::class)->call('pausar', 4)->assertSee('Robô pausado por 4 h.');
    expect($robo->pausa())->motivo->toBe('Pausado por Maria da Silva pelo painel')
        ->automatica->toBeFalse()
        ->and($robo->pausadoAte()->toDateTimeString())->toBe('2026-10-21 14:00:00');

    Livewire::test(Robo::class)->assertSee('Pausado')->call('retomar')->assertSee('Robô retomado.');
    expect($robo->pausa())->toBeNull();
});

test('rodar agora só com o robô ligado e sem pausa', function () {
    Queue::fake();
    config(['walle.admins' => [$this->usuario->id]]);
    session()->put('walle.senha_confirmada_em', now()->getTimestamp());

    Livewire::test(Robo::class)->call('rodarAgora')->assertSee('O robô está desligado neste servidor');
    Queue::assertNothingPushed();

    config(['walle.autorizadas.ativo' => true]);
    app(RoboAutorizadas::class)->pausar(now()->addHour());
    Livewire::test(Robo::class)->call('rodarAgora')->assertSee('retome antes');
    Queue::assertNothingPushed();

    app(RoboAutorizadas::class)->retomar();
    Livewire::test(Robo::class)->call('rodarAgora')->assertSee('Ciclo pedido');
    Queue::assertPushed(RodarCicloAutorizadas::class);
});

test('quem não é admin recebe 403 nas três ações do robô, sem fila e sem auditoria', function (string $acao, array $argumentos) {
    Queue::fake();
    config(['walle.admins' => [], 'walle.autorizadas.ativo' => true]);
    session()->put('walle.senha_confirmada_em', now()->getTimestamp());

    Livewire::test(Robo::class)->call($acao, ...$argumentos)->assertForbidden();

    Queue::assertNothingPushed();
    expect(app(RoboAutorizadas::class)->pausa())->toBeNull()
        ->and(Auditoria::where('acao', 'like', 'autorizadas.robo.%')->count())->toBe(0);
})->with([
    'pausar' => ['pausar', [4]],
    'retomar' => ['retomar', []],
    'rodarAgora' => ['rodarAgora', []],
]);

test('admin sem a senha confirmada só recebe o pedido de senha nas três ações', function (string $acao, array $argumentos) {
    Queue::fake();
    config(['walle.admins' => [$this->usuario->id], 'walle.autorizadas.ativo' => true]);
    session()->forget('walle.senha_confirmada_em');

    Livewire::test(Robo::class)->call($acao, ...$argumentos)
        ->assertSet('pedindoSenha', true)
        ->assertSet('acaoPendente', $acao);

    Queue::assertNothingPushed();
    expect(app(RoboAutorizadas::class)->pausa())->toBeNull()
        ->and(Auditoria::where('acao', 'like', 'autorizadas.robo.%')->count())->toBe(0);
})->with([
    'pausar' => ['pausar', [4]],
    'retomar' => ['retomar', []],
    'rodarAgora' => ['rodarAgora', []],
]);

test('admin pausa o robô depois de confirmar a senha e fica na auditoria', function () {
    config(['walle.admins' => [$this->usuario->id], 'walle.autorizadas.ativo' => true]);
    session()->forget('walle.senha_confirmada_em');

    Livewire::test(Robo::class)
        ->call('pausar', 4)
        ->assertSet('pedindoSenha', true)
        ->set('senhaConfirmacao', 'segredo')
        ->call('confirmarSenha')
        ->assertSet('pedindoSenha', false)
        ->assertSee('Robô pausado por 4 h.');

    expect(app(RoboAutorizadas::class)->pausa())->motivo->toBe('Pausado por Maria da Silva pelo painel')
        ->and(Auditoria::where('acao', 'autorizadas.robo.pausar')->sole()->detalhes)->toBe(['horas' => 4]);
});

test('admin retoma o robô depois de confirmar a senha e fica na auditoria', function () {
    config(['walle.admins' => [$this->usuario->id], 'walle.autorizadas.ativo' => true]);
    session()->forget('walle.senha_confirmada_em');
    app(RoboAutorizadas::class)->pausar(now()->addHour());

    Livewire::test(Robo::class)
        ->call('retomar')
        ->assertSet('pedindoSenha', true)
        ->set('senhaConfirmacao', 'segredo')
        ->call('confirmarSenha')
        ->assertSet('pedindoSenha', false)
        ->assertSee('Robô retomado.');

    expect(app(RoboAutorizadas::class)->pausa())->toBeNull()
        ->and(Auditoria::where('acao', 'autorizadas.robo.retomar')->count())->toBe(1);
});

test('admin dispara o ciclo depois de confirmar a senha e fica na auditoria', function () {
    Queue::fake();
    config(['walle.admins' => [$this->usuario->id], 'walle.autorizadas.ativo' => true]);
    session()->forget('walle.senha_confirmada_em');

    Livewire::test(Robo::class)
        ->call('rodarAgora')
        ->assertSet('pedindoSenha', true)
        ->assertDontSee('Ciclo pedido');

    Queue::assertNothingPushed();

    Livewire::test(Robo::class)
        ->call('rodarAgora')
        ->set('senhaConfirmacao', 'segredo')
        ->call('confirmarSenha')
        ->assertSet('pedindoSenha', false)
        ->assertSee('Ciclo pedido: começa em instantes.');

    Queue::assertPushed(RodarCicloAutorizadas::class);
    expect(Auditoria::where('acao', 'autorizadas.robo.rodar_agora')->count())->toBe(1);
});

test('robô desligado continua avisando sem enfileirar e sem auditar, mesmo para admin', function () {
    Queue::fake();
    config(['walle.admins' => [$this->usuario->id], 'walle.autorizadas.ativo' => false]);
    session()->put('walle.senha_confirmada_em', now()->getTimestamp());

    Livewire::test(Robo::class)->call('rodarAgora')
        ->assertSee('O robô está desligado neste servidor')
        ->assertSet('pedindoSenha', false);

    Queue::assertNothingPushed();
    expect(Auditoria::where('acao', 'autorizadas.robo.rodar_agora')->count())->toBe(0);
});

test('o diagnóstico explica por que a chave entra ou não na fila', function () {
    $semNada = chaveValida(1);
    $noHistorico = chaveValida(2);
    $antiga = chaveValida(3);
    criarHistoricoContingencia(7, $noHistorico, '2026-10-20 10:00:00');
    criarHistoricoContingencia(7, $antiga, '2026-05-02 10:00:00');
    autorizarNaSefaz(7, $autorizada = chaveValida(4), '2026-10-01');
    DB::table('history_contingencia_auth')->insert([
        ['fk_c_cliente' => 7, 'chave' => $mt = chaveValida(5, uf: '51'), 'autorizada' => 'N', 'referencia' => '2026-10-01', 'data_consulta_sefaz_bot' => null],
        ['fk_c_cliente' => 7, 'chave' => $recente = chaveValida(6), 'autorizada' => 'N', 'referencia' => '2026-10-01', 'data_consulta_sefaz_bot' => '2026-10-21 09:00:00'],
        ['fk_c_cliente' => 7, 'chave' => $naFila = chaveValida(7), 'autorizada' => 'N', 'referencia' => '2026-10-01', 'data_consulta_sefaz_bot' => null],
        ['fk_c_cliente' => 7, 'chave' => $vaiSair = chaveValida(8), 'autorizada' => 'N', 'referencia' => '2026-07-19', 'data_consulta_sefaz_bot' => null],
    ]);

    expect(diagnosticoDe($semNada)->fila)->toBe(['situacao' => 'fora', 'texto' => 'Não está no histórico de contingências: o robô não conhece esta chave.'])
        ->and(diagnosticoDe($noHistorico)->fila['texto'])->toContain('ainda não foi semeada')
        ->and(diagnosticoDe($antiga)->fila['texto'])->toContain('passou da retenção')
        ->and(diagnosticoDe($autorizada)->fila['situacao'])->toBe('ok')
        ->and(diagnosticoDe($mt)->fila['texto'])->toContain('De outra UF (MT)')
        ->and(diagnosticoDe($recente)->fila['texto'])->toContain('volta para a fila às 12:00')
        ->and(diagnosticoDe($naFila)->fila['texto'])->toContain('na fila')
        ->and(diagnosticoDe($vaiSair)->fila)->toBe(['situacao' => 'fora', 'texto' => 'A referência passou da retenção de 73 dias: sai da base no próximo ciclo.'])
        ->and(diagnosticoDe($noHistorico)->clienteConhecido())->toBe(7);
});

test('consulta à mão: autorizada fica gravada com o cliente e a data fiscal do histórico', function () {
    config(['walle.admins' => [$this->usuario->id]]);
    session()->put('walle.senha_confirmada_em', now()->getTimestamp());
    $chave = chaveValida(10);
    criarHistoricoContingencia(7, $chave, '2026-10-18 15:00:00');
    $sefaz = sefazFalsa([$chave => '100']);

    Livewire::test(Chave::class)
        ->set('entrada', 'NFe '.chunk_split($chave, 4, ' '))
        ->call('verificar')
        ->assertSet('entrada', $chave)
        ->assertSee('ainda não foi semeada')
        ->call('consultarSefaz')
        ->assertSee('Autorizada')
        ->assertSee('152260000000001')
        ->assertSee('Gravada como autorizada na base.');

    expect($sefaz->consultadas)->toBe([$chave])
        ->and(DB::table('history_contingencia_auth')->where('chave', $chave)->first())
        ->fk_c_cliente->toBe(7)
        ->autorizada->toBe('S')
        ->referencia->toBe('2026-10-18');
});

test('consulta à mão que não volta autorizada não grava nada', function () {
    config(['walle.admins' => [$this->usuario->id]]);
    session()->put('walle.senha_confirmada_em', now()->getTimestamp());
    $chave = chaveValida(11);
    sefazFalsa();

    Livewire::test(Chave::class)->set('entrada', $chave)->call('consultarSefaz')->assertSee('Não existe na Sefaz');

    expect(DB::table('history_contingencia_auth')->count())->toBe(0);
});

test('a gravação à mão não troca a referência nem apaga o cliente', function () {
    $chave = chaveValida(12);
    DB::table('history_contingencia_auth')->insert(['fk_c_cliente' => 7, 'chave' => $chave, 'autorizada' => 'N', 'referencia' => '2026-10-03']);

    app(RoboAutorizadas::class)->registrarAutorizada(ChaveDeAcesso::de($chave), 0, '2026-10-21');

    expect(DB::table('history_contingencia_auth')->where('chave', $chave)->first())
        ->fk_c_cliente->toBe(7)
        ->autorizada->toBe('S')
        ->referencia->toBe('2026-10-03')
        ->data_consulta_sefaz_bot->toBe('2026-10-21 10:00:00');
});

test('consumo indevido numa consulta à mão pausa o robô e bloqueia as próximas', function () {
    config(['walle.admins' => [$this->usuario->id]]);
    session()->put('walle.senha_confirmada_em', now()->getTimestamp());
    $chave = chaveValida(13);
    $sefaz = sefazFalsa([$chave => '656']);

    Livewire::test(Chave::class)->set('entrada', $chave)->call('consultarSefaz')->assertSee('Consumo indevido');

    expect(app(RoboAutorizadas::class)->pausa())->automatica->toBeTrue();

    Livewire::test(Chave::class)->set('entrada', $chave)->call('consultarSefaz')->assertSee('bloqueou o certificado');
    expect($sefaz->consultadas)->toBe([$chave]);
});

test('chave inválida ou consultas demais não chegam na Sefaz', function () {
    config(['walle.admins' => [$this->usuario->id]]);
    session()->put('walle.senha_confirmada_em', now()->getTimestamp());
    $sefaz = sefazFalsa();

    Livewire::test(Chave::class)->set('entrada', '123')->call('consultarSefaz')->assertSee('Chave deve ter 44 dígitos');

    foreach (range(1, Chave::CONSULTAS_POR_MINUTO) as $i) {
        RateLimiter::hit('autorizadas:consulta-manual', 60);
    }
    Livewire::test(Chave::class)->set('entrada', chaveValida(14))->call('consultarSefaz')->assertSee('Muitas consultas seguidas');

    expect($sefaz->consultadas)->toBe([]);
});

test('o limite conta a tentativa antes de decidir, então nenhuma consulta passa do teto', function () {
    config(['walle.admins' => [$this->usuario->id]]);
    session()->put('walle.senha_confirmada_em', now()->getTimestamp());
    $sefaz = sefazFalsa();
    $chave = chaveValida(20);

    foreach (range(1, Chave::CONSULTAS_POR_MINUTO - 1) as $i) {
        Livewire::test(Chave::class)->set('entrada', $chave)->call('consultarSefaz')->assertSet('erro', null);
    }

    expect(RateLimiter::attempts('autorizadas:consulta-manual'))->toBe(9);

    // O teste é sequencial: com o contador em 9, a próxima tentativa recebe 10 e passa, a seguinte
    // recebe 11 e é barrada. Prova que a decisão vem da tentativa já somada, não da leitura do contador.
    Livewire::test(Chave::class)->set('entrada', $chave)->call('consultarSefaz')->assertSet('erro', null);
    Livewire::test(Chave::class)->set('entrada', $chave)->call('consultarSefaz')->assertSee('Muitas consultas seguidas');

    expect(RateLimiter::attempts('autorizadas:consulta-manual'))->toBe(11)
        ->and($sefaz->consultadas)->toBe(array_fill(0, Chave::CONSULTAS_POR_MINUTO, $chave));
});

test('o diálogo de senha da consulta à Sefaz fala da consulta e as outras telas mantêm o texto padrão', function () {
    config(['walle.admins' => [$this->usuario->id], 'walle.autorizadas.ativo' => true]);
    session()->forget('walle.senha_confirmada_em');
    $chave = chaveValida(21);
    criarHistoricoContingencia(7, $chave, '2026-10-18 15:00:00');

    Livewire::test(Chave::class)
        ->set('entrada', $chave)
        ->call('consultarSefaz')
        ->assertSet('pedindoSenha', true)
        ->assertSee('consulta a Sefaz com o certificado de verdade')
        ->assertDontSee('mexe no servidor do cliente');

    Livewire::test(Robo::class)
        ->call('pausar', 4)
        ->assertSet('pedindoSenha', true)
        ->assertSee('mexe no servidor do cliente ou mostra um segredo');
});

test('verificar continua livre para quem não é admin e não chama a Sefaz', function () {
    $chave = chaveValida(15);
    $sefaz = sefazFalsa();

    Livewire::test(Chave::class)
        ->set('entrada', 'NFe '.chunk_split($chave, 4, ' '))
        ->call('verificar')
        ->assertSet('entrada', $chave)
        ->assertSee($chave)
        ->assertDontSee('Consultar na Sefaz');

    expect($sefaz->consultadas)->toBe([]);
});

test('quem não é admin recebe 403 na consulta à Sefaz, sem chamar, gravar, auditar nem pausar o robô', function () {
    $chave = chaveValida(16);
    criarHistoricoContingencia(7, $chave, '2026-10-18 15:00:00');
    $sefaz = sefazFalsa([$chave => '100']);
    session()->put('walle.senha_confirmada_em', now()->getTimestamp());

    Livewire::test(Chave::class)
        ->set('entrada', $chave)
        ->call('consultarSefaz')
        ->assertForbidden()
        ->assertSet('pedindoSenha', false);

    expect($sefaz->consultadas)->toBe([])
        ->and(DB::table('history_contingencia_auth')->count())->toBe(0)
        ->and(Auditoria::where('acao', 'autorizadas.consulta_sefaz')->count())->toBe(0)
        ->and(app(RoboAutorizadas::class)->pausa())->toBeNull();
});

test('admin sem a senha confirmada só recebe o pedido de senha e não consulta', function () {
    config(['walle.admins' => [$this->usuario->id]]);
    session()->forget('walle.senha_confirmada_em');
    $chave = chaveValida(17);
    criarHistoricoContingencia(7, $chave, '2026-10-18 15:00:00');
    $sefaz = sefazFalsa([$chave => '100']);

    Livewire::test(Chave::class)
        ->set('entrada', $chave)
        ->call('consultarSefaz')
        ->assertSet('pedindoSenha', true)
        ->assertSet('acaoPendente', 'consultarSefaz')
        ->assertDontSee('Gravada como autorizada na base.');

    expect($sefaz->consultadas)->toBe([])
        ->and(DB::table('history_contingencia_auth')->count())->toBe(0)
        ->and(Auditoria::where('acao', 'autorizadas.consulta_sefaz')->count())->toBe(0);
});

test('admin consulta a Sefaz depois de confirmar a senha, grava a autorizada e audita', function () {
    config(['walle.admins' => [$this->usuario->id]]);
    session()->forget('walle.senha_confirmada_em');
    $chave = chaveValida(18);
    criarHistoricoContingencia(7, $chave, '2026-10-18 15:00:00');
    $sefaz = sefazFalsa([$chave => '100']);

    Livewire::test(Chave::class)
        ->set('entrada', $chave)
        ->call('consultarSefaz')
        ->assertSet('pedindoSenha', true)
        ->set('senhaConfirmacao', 'segredo')
        ->call('confirmarSenha')
        ->assertSet('pedindoSenha', false)
        ->assertSee('Gravada como autorizada na base.');

    expect($sefaz->consultadas)->toBe([$chave])
        ->and(DB::table('history_contingencia_auth')->where('chave', $chave)->first())
        ->fk_c_cliente->toBe(7)
        ->autorizada->toBe('S')
        ->and(Auditoria::where('acao', 'autorizadas.consulta_sefaz')->sole()->detalhes)
        ->toEqual(['chave' => $chave, 'cStat' => '100']);
});

test('o admin vê o botão de consultar na Sefaz e quem não é admin não vê', function () {
    $chave = chaveValida(19);

    Livewire::test(Chave::class)->set('entrada', $chave)->assertDontSee('Consultar na Sefaz');

    config(['walle.admins' => [$this->usuario->id]]);

    Livewire::test(Chave::class)->set('entrada', $chave)->assertSee('Consultar na Sefaz');
});
