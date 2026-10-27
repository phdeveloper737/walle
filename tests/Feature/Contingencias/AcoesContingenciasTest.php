<?php

use App\Contingencias\AcoesContingencias;
use App\Contingencias\Cliente\ConectorDeCliente;
use App\Contingencias\Cliente\ConexaoComCliente;
use App\Livewire\Contingencias\DoCliente;
use App\Models\Auditoria;
use App\Models\Usuario;
use App\Sactus\Ssh\ResultadoComando;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

const SSH_CONTEINER = ['10.20.30.40', 2222];

/** Cliente Docker com o SSH do contêiner cadastrado; devolve [id do cliente, servidor SSH falso]. */
function clienteComConteinerSsh(array $cliente = [], array $servidor = []): array
{
    $id = criarClienteHelpDesk($cliente, array_merge([
        'pasta_multiempresa' => 'S',
        'servidor_docker' => SSH_CONTEINER[0],
        'porta_ssh_docker' => (string) SSH_CONTEINER[1],
        'usuario_ssh_docker' => 'root',
        'senha_ssh_docker' => 'senha-do-conteiner',
    ], $servidor));

    $ssh = sshFalso()
        ->servidor(SSH_CONTEINER[0], SSH_CONTEINER[1], 'senha-do-conteiner')
        ->regra(SSH_CONTEINER[0], SSH_CONTEINER[1], fn (string $comando) => str_contains($comando, ' && ln ') ? new ResultadoComando(0) : null);

    return [$id, $ssh];
}

/** A tabela pdv não existe nas imitações do Sactus; a temporária não encerra a transação do teste. */
function pdvsNoServidorDoCliente(array $pdvs): void
{
    Schema::create('pdv', function ($table) {
        $table->temporary();
        $table->integer('pdv_id');
        $table->integer('store_id');
    });

    foreach ($pdvs as [$loja, $pdv]) {
        DB::table('pdv')->insert(['store_id' => $loja, 'pdv_id' => $pdv]);
    }
}

function adminDeContingencias(bool $admin = true, bool $senha = true): Usuario
{
    $usuario = Usuario::find(criarUsuarioHelpDesk());
    test()->actingAs($usuario);
    config(['walle.admins' => $admin ? [$usuario->id] : [], 'walle.contingencias.ativo' => true]);

    if ($senha) {
        session()->put('walle.senha_confirmada_em', now()->getTimestamp());
    }

    return $usuario;
}

function conectorDoClienteNaoChamado(): void
{
    test()->mock(ConectorDeCliente::class)->shouldNotReceive('conectar');
}

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-27 10:00:00'));
});

test('admin com senha confirmada reenvia 2 cupons ao PDV pelo SSH do contêiner', function () {
    adminDeContingencias();
    [$cliente, $ssh] = clienteComConteinerSsh();
    $chaveA = criarContingenciaSactus(['cupom' => '500', 'pdv' => '2'], chave: chaveValida(1));
    $chaveB = criarContingenciaSactus(['cupom' => '501', 'pdv' => '2'], chave: chaveValida(2));
    servidorDoClienteNoBancoDeTestes();

    Livewire::withoutLazyLoading()->test(DoCliente::class, ['clienteId' => $cliente])
        ->set('selecionadas', ['1_2_500', '1_2_501'])
        ->call('reenviarAoPdv')
        ->assertSee('2 cupons enviados ao PDV');

    $ts = now()->getTimestamp();
    $comandos = $ssh->comandosEm(...SSH_CONTEINER);

    expect($comandos)->toHaveCount(2)
        ->and($comandos[0])->toContain('funcid="858"', 'argum="'.$chaveA.'"', "/var/sactus/snd/0001/002/comando_0001_002_{$ts}_102.xml", 'store="0001" pdv="002"')
        ->and($comandos[1])->toContain('argum="'.$chaveB.'"', '_'.($ts + 1).'_102.xml');

    $auditoria = Auditoria::where('acao', 'contingencias.reenviar_pdv')->sole();
    expect($auditoria->fk_c_cliente)->toBe($cliente)
        ->and($auditoria->usuario)->toBe('Maria da Silva')
        ->and(json_encode($auditoria->detalhes))->not->toContain($chaveA, 'printf', 'senha')
        ->and($auditoria->detalhes)->toMatchArray(['quantidade' => 2, 'enviados' => 2, 'resultado' => 'ok']);
});

test('sem senha confirmada nada é enviado e a janela de senha abre', function () {
    adminDeContingencias(senha: false);
    [$cliente, $ssh] = clienteComConteinerSsh();
    criarContingenciaSactus(['cupom' => '500']);
    servidorDoClienteNoBancoDeTestes();

    Livewire::withoutLazyLoading()->test(DoCliente::class, ['clienteId' => $cliente])
        ->set('selecionadas', ['1_2_500'])
        ->call('reenviarAoPdv')
        ->assertSet('pedindoSenha', true);

    expect($ssh->comandos)->toBe([])->and(Auditoria::where('acao', 'contingencias.reenviar_pdv')->count())->toBe(0);
});

test('quem não é admin recebe 403 e nada é enviado', function () {
    adminDeContingencias(admin: false);
    [$cliente, $ssh] = clienteComConteinerSsh();
    criarContingenciaSactus(['cupom' => '500']);
    servidorDoClienteNoBancoDeTestes();

    Livewire::withoutLazyLoading()->test(DoCliente::class, ['clienteId' => $cliente])
        ->set('selecionadas', ['1_2_500'])
        ->call('reenviarAoPdv')
        ->assertForbidden();
    Livewire::withoutLazyLoading()->test(DoCliente::class, ['clienteId' => $cliente])->call('reprocessar')->assertForbidden();

    expect($ssh->comandos)->toBe([]);
});

test('com o robô desligado nem o conector nem o SSH são chamados', function () {
    adminDeContingencias();
    config(['walle.contingencias.ativo' => false]);
    [$cliente, $ssh] = clienteComConteinerSsh();
    conectorDoClienteNaoChamado();

    $resultado = app(AcoesContingencias::class)->reenviarAoPdv($cliente, [['loja' => 1, 'pdv' => 2, 'cupom' => 500]]);
    $reprocessar = app(AcoesContingencias::class)->reprocessar($cliente);

    expect($resultado->ok)->toBeFalse()
        ->and($resultado->mensagem)->toBe('O robô das contingências está desligado; a ação não foi enviada.')
        ->and($reprocessar->mensagem)->toBe($resultado->mensagem)
        ->and($ssh->comandos)->toBe([]);
});

test('falha de conexão mostra a mensagem sem segredos e não altera os pendentes', function () {
    adminDeContingencias();
    [$cliente, $ssh] = clienteComConteinerSsh();
    criarContingenciaVersatus($cliente, ['pdv' => 2, 'cupom' => '700']);
    criarContingenciaSactus(['cupom' => '500']);
    servidorDoClienteNoBancoDeTestes();

    $tela = Livewire::withoutLazyLoading()->test(DoCliente::class, ['clienteId' => $cliente])->assertSee('Cupom 500');

    servidorDoClienteForaDoAr();

    $tela->set('selecionadas', ['1_2_500'])
        ->call('reenviarAoPdv')
        ->assertSee('O servidor do cliente não respondeu')
        ->assertDontSee('senha-do-conteiner')
        ->assertSee('Cupom 500')
        ->assertSet('aba', 'pendentes');

    expect($ssh->comandos)->toBe([])
        ->and(Auditoria::where('acao', 'contingencias.reenviar_pdv')->sole()->detalhes['resultado'])->toBe('falha');
});

test('cupom sem chave mostra o texto e o outro é enviado', function () {
    adminDeContingencias();
    [$cliente, $ssh] = clienteComConteinerSsh();
    $chave = criarContingenciaSactus(['cupom' => '501'], chave: chaveValida(2));
    servidorDoClienteNoBancoDeTestes();

    $resultado = app(AcoesContingencias::class)->reenviarAoPdv($cliente, [
        ['loja' => 1, 'pdv' => 2, 'cupom' => 999],
        ['loja' => 1, 'pdv' => 2, 'cupom' => 501],
    ]);

    expect($resultado->ok)->toBeFalse()
        ->and($resultado->enviados)->toBe(1)
        ->and($resultado->falhas)->toBe(['Cupom 1/2/999 sem chave de acesso no banco do cliente.'])
        ->and($ssh->comandosEm(...SSH_CONTEINER))->toHaveCount(1)
        ->and($ssh->comandosEm(...SSH_CONTEINER)[0])->toContain($chave);
});

test('cupom fora dos pendentes é ignorado', function () {
    adminDeContingencias();
    [$cliente, $ssh] = clienteComConteinerSsh();
    criarContingenciaSactus(['cupom' => '500']);
    criarContingenciaSactus(['cupom' => '777']);
    servidorDoClienteNoBancoDeTestes();

    Livewire::withoutLazyLoading()->test(DoCliente::class, ['clienteId' => $cliente])
        ->set('selecionadas', ['1_2_777_inventado', '1_2_888'])
        ->call('reenviarAoPdv')
        ->assertSee('Selecione ao menos um cupom');

    expect($ssh->comandos)->toBe([]);
});

test('cliente sem SSH do contêiner cadastrado não envia nada', function () {
    adminDeContingencias();
    $cliente = criarClienteHelpDesk([], ['pasta_multiempresa' => 'S', 'servidor_docker' => '10.20.30.40']);
    $ssh = sshFalso();
    conectorDoClienteNaoChamado();

    $resultado = app(AcoesContingencias::class)->reenviarAoPdv($cliente, [['loja' => 1, 'pdv' => 2, 'cupom' => 500]]);

    expect($resultado->mensagem)->toBe('Cadastro SSH do contêiner incompleto')
        ->and($ssh->comandos)->toBe([]);
});

test('loja ou PDV fora da faixa não geram comando', function () {
    adminDeContingencias();
    [$cliente, $ssh] = clienteComConteinerSsh();
    servidorDoClienteNoBancoDeTestes();

    $resultado = app(AcoesContingencias::class)->reenviarAoPdv($cliente, [['loja' => 10000, 'pdv' => 2, 'cupom' => 1], ['loja' => 1, 'pdv' => 1000, 'cupom' => 1]]);

    expect($resultado->enviados)->toBe(0)->and($resultado->falhas)->toHaveCount(2)->and($ssh->comandos)->toBe([]);
});

test('reprocessar grava um comando 556 por PDV', function () {
    adminDeContingencias();
    [$cliente, $ssh] = clienteComConteinerSsh();
    pdvsNoServidorDoCliente([[1, 2], [3, 14]]);
    servidorDoClienteNoBancoDeTestes();

    Livewire::withoutLazyLoading()->test(DoCliente::class, ['clienteId' => $cliente])
        ->call('reprocessar')
        ->assertSee('2 PDVs');

    $ts = now()->getTimestamp();
    $comandos = $ssh->comandosEm(...SSH_CONTEINER);

    expect($comandos)->toHaveCount(2)
        ->and($comandos[0])->toContain('funcid="556"', 'argum="ajustar_contingencias"', 'store="1" pdv="2"', "/var/sactus/snd/0001/002/comando_0001_002_{$ts}_102.xml")
        ->and($comandos[1])->toContain('store="3" pdv="14"', '/var/sactus/snd/0003/014/comando_0003_014_');

    expect(Auditoria::where('acao', 'contingencias.reprocessar')->sole()->detalhes)->toMatchArray(['quantidade' => 2, 'enviados' => 2, 'resultado' => 'ok']);
});

test('reprocessar sem PDV avisa e não grava nada', function () {
    adminDeContingencias();
    [$cliente, $ssh] = clienteComConteinerSsh();
    pdvsNoServidorDoCliente([]);
    servidorDoClienteNoBancoDeTestes();

    $resultado = app(AcoesContingencias::class)->reprocessar($cliente);

    expect($resultado->ok)->toBeFalse()
        ->and($resultado->mensagem)->toBe('Nenhum PDV foi encontrado no banco do cliente. Nenhum XML foi gravado.')
        ->and($ssh->comandos)->toBe([]);
});

test('reprocessar de cliente remapeado usa o cliente do 220 (1484 vira 1485)', function () {
    adminDeContingencias();
    [$cliente, $ssh] = clienteComConteinerSsh(['id' => 1485]);
    pdvsNoServidorDoCliente([[1, 2]]);
    test()->mock(ConectorDeCliente::class)
        ->shouldReceive('conectar')
        ->withArgs(fn (int $id) => $id === 1485)
        ->once()
        ->andReturn(new ConexaoComCliente(DB::connection(), DB::getDatabaseName(), fecharAoTerminar: false));

    $resultado = app(AcoesContingencias::class)->reprocessar(1484);

    expect($cliente)->toBe(1485)
        ->and($resultado->enviados)->toBe(1)
        ->and($ssh->comandosEm(...SSH_CONTEINER))->toHaveCount(1)
        ->and(Auditoria::where('acao', 'contingencias.reprocessar')->sole()->fk_c_cliente)->toBe(1484);
});

test('destino já existente não é sobrescrito: grava no segundo seguinte', function () {
    adminDeContingencias();
    [$cliente, $ssh] = clienteComConteinerSsh();
    criarContingenciaSactus(['cupom' => '500'], chave: chaveValida(1));
    servidorDoClienteNoBancoDeTestes();
    $ts = now()->getTimestamp();
    $ssh->regra(SSH_CONTEINER[0], SSH_CONTEINER[1], fn (string $comando) => str_contains($comando, "_{$ts}_102.xml") ? new ResultadoComando(4) : new ResultadoComando(0));

    $resultado = app(AcoesContingencias::class)->reenviarAoPdv($cliente, [['loja' => 1, 'pdv' => 2, 'cupom' => 500]]);
    $comandos = $ssh->comandosEm(...SSH_CONTEINER);

    expect($resultado->ok)->toBeTrue()
        ->and($comandos)->toHaveCount(2)
        ->and($comandos[1])->toContain('_'.($ts + 1).'_102.xml', 'reqtime="'.($ts + 1).'"')
        ->and($comandos[1])->toContain(' && ln ')->not->toContain('mkdir');
});

test('todos os nomes ocupados falham com mensagem própria depois de 5 tentativas', function () {
    adminDeContingencias();
    [$cliente, $ssh] = clienteComConteinerSsh();
    criarContingenciaSactus(['cupom' => '500'], chave: chaveValida(1));
    servidorDoClienteNoBancoDeTestes();
    $ssh->regra(SSH_CONTEINER[0], SSH_CONTEINER[1], fn () => new ResultadoComando(4));

    $resultado = app(AcoesContingencias::class)->reenviarAoPdv($cliente, [['loja' => 1, 'pdv' => 2, 'cupom' => 500]]);

    expect($resultado->enviados)->toBe(0)
        ->and($resultado->falhas[0])->toContain('mesmo nome')
        ->and($ssh->comandosEm(...SSH_CONTEINER))->toHaveCount(AcoesContingencias::TENTATIVAS_DE_NOME);
});

test('pasta de envio ausente falha com a mensagem e não é criada', function () {
    adminDeContingencias();
    [$cliente, $ssh] = clienteComConteinerSsh();
    criarContingenciaSactus(['cupom' => '500'], chave: chaveValida(1));
    criarContingenciaSactus(['cupom' => '501', 'pdv' => '3'], chave: chaveValida(2));
    servidorDoClienteNoBancoDeTestes();
    $ssh->regra(SSH_CONTEINER[0], SSH_CONTEINER[1], fn (string $comando) => str_contains($comando, '/002/') ? new ResultadoComando(3) : new ResultadoComando(0));

    $resultado = app(AcoesContingencias::class)->reenviarAoPdv($cliente, [
        ['loja' => 1, 'pdv' => 2, 'cupom' => 500],
        ['loja' => 1, 'pdv' => 3, 'cupom' => 501],
    ]);

    expect($resultado->enviados)->toBe(1)
        ->and($resultado->falhas)->toBe(['Cupom 1/2/500: Diretório de envio não existe.']);

    foreach ($ssh->comandosEm(...SSH_CONTEINER) as $comando) {
        expect($comando)->not->toContain('mkdir');
    }

    pdvsNoServidorDoCliente([[1, 2], [1, 3]]);
    $reprocessar = app(AcoesContingencias::class)->reprocessar($cliente);

    expect($reprocessar->ok)->toBeFalse()
        ->and($reprocessar->mensagem)->toContain('Diretório de envio não existe')
        ->and($reprocessar->mensagem)->not->toContain(SSH_CONTEINER[0])
        ->and($reprocessar->enviados)->toBe(0);
});

test('cupom repetido em datas diferentes pega a chave da data certa', function () {
    adminDeContingencias();
    [$cliente, $ssh] = clienteComConteinerSsh();
    $antiga = criarContingenciaSactus(['cupom' => '500', 'fiscal_date' => '2026-10-10 09:00:00'], chave: chaveValida(1));
    $nova = criarContingenciaSactus(['cupom' => '500', 'fiscal_date' => '2026-10-19 09:00:00'], chave: chaveValida(2));
    servidorDoClienteNoBancoDeTestes();

    $resultado = app(AcoesContingencias::class)->reenviarAoPdv($cliente, [['loja' => 1, 'pdv' => 2, 'cupom' => 500, 'data' => '2026-10-19 09:00:00']]);
    $comandos = $ssh->comandosEm(...SSH_CONTEINER);

    expect($resultado->ok)->toBeTrue()
        ->and($comandos[0])->toContain('argum="'.$nova.'"')->not->toContain($antiga);
});

test('a tela leva a data do pendente e conta só os cupons que serão enviados', function () {
    adminDeContingencias();
    [$cliente, $ssh] = clienteComConteinerSsh();
    criarContingenciaSactus(['cupom' => '500', 'fiscal_date' => '2026-10-10 09:00:00'], chave: chaveValida(1));
    criarContingenciaSactus(['cupom' => '500', 'fiscal_date' => '2026-10-19 09:00:00'], chave: chaveValida(2));
    servidorDoClienteNoBancoDeTestes();

    $tela = Livewire::withoutLazyLoading()->test(DoCliente::class, ['clienteId' => $cliente])
        ->set('selecionadas', ['1_2_500', '1_2_inventado'])
        ->assertSee('Reenviar ao PDV (1)')
        ->assertDontSee('Reenviar ao PDV (2)');

    $tela->call('reenviarAoPdv');

    expect($ssh->comandosEm(...SSH_CONTEINER))->toHaveCount(1);
});

test('ação em andamento é auditada', function () {
    adminDeContingencias();
    [$cliente, $ssh] = clienteComConteinerSsh();
    conectorDoClienteNaoChamado();
    $trava = Cache::lock('contingencias:acao:'.$cliente, 120);
    $trava->get();

    $resultado = app(AcoesContingencias::class)->reenviarAoPdv($cliente, [['loja' => 1, 'pdv' => 2, 'cupom' => 500]]);

    expect($resultado->mensagem)->toContain('Já existe uma ação em andamento')
        ->and($ssh->comandos)->toBe([])
        ->and(Auditoria::where('acao', 'contingencias.reenviar_pdv')->sole()->detalhes['resultado'])->toBe('ocupado');

    $trava->release();
});

test('a trava do reprocessar é do servidor remapeado', function () {
    adminDeContingencias();
    [$cliente, $ssh] = clienteComConteinerSsh(['id' => 1637]);
    conectorDoClienteNaoChamado();
    $trava = Cache::lock('contingencias:acao:1637', 120);
    $trava->get();

    $resultado = app(AcoesContingencias::class)->reprocessar(1656);

    expect($resultado->mensagem)->toContain('Já existe uma ação em andamento');

    $trava->release();
});

test('reprocessar para no primeiro erro de gravação', function () {
    adminDeContingencias();
    [$cliente, $ssh] = clienteComConteinerSsh();
    $ssh->regra(SSH_CONTEINER[0], SSH_CONTEINER[1], fn (string $comando) => new ResultadoComando(1, '', 'Permission denied: senha-do-conteiner'));
    pdvsNoServidorDoCliente([[1, 2], [1, 3]]);
    servidorDoClienteNoBancoDeTestes();

    $resultado = app(AcoesContingencias::class)->reprocessar($cliente);

    expect($resultado->ok)->toBeFalse()
        ->and($resultado->enviados)->toBe(0)
        ->and($resultado->mensagem)->not->toContain('senha-do-conteiner')
        ->and($ssh->comandosEm(...SSH_CONTEINER))->toHaveCount(1);
});
