<?php

use App\Jobs\ExecutarAcaoSactus;
use App\Jobs\ProcessarClienteSactus;
use App\Jobs\RodarCicloSactus;
use App\Livewire\Sactus\Clientes;
use App\Livewire\Sactus\Robo;
use App\Livewire\Sactus\Verificador;
use App\Models\Auditoria;
use App\Models\PainelSactusCfg;
use App\Models\SactusCfgEdicao;
use App\Models\Usuario;
use App\Sactus\ArquivoCfg;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

beforeEach(function () {
    config(['walle.admins' => []]);
    $this->usuario = Usuario::find(criarUsuarioHelpDesk());
    $this->actingAs($this->usuario);
});

function tornarAdmin(Usuario $usuario): void
{
    config(['walle.admins' => [$usuario->id]]);
}

/** @param array<string, mixed> $dados */
function coletaSactus(int $clienteId, array $dados = []): void
{
    $cfg = $dados['conteudo'] ?? cfgSactus();

    PainelSactusCfg::create(array_merge([
        'fk_c_cliente' => $clienteId, 'metodo' => 'NORMAL', 'host' => '200.1.2.3', 'porta' => 22, 'usuario' => 'root',
        'status' => 'S', 'erro' => '', 'conteudo' => $cfg, 'json' => json_encode(ArquivoCfg::ler($cfg)), 'data' => now(),
    ], $dados));
}

test('a tela abre nas quatro abas', function () {
    $this->get(route('sactus'))->assertOk()->assertSee(['Clientes', 'Verificador', 'Pedidos e ações', 'Robô', 'Sactus do walle está']);
    $this->get(route('sactus', ['aba' => 'verificador']))->assertOk()->assertSee('Escolha uma flag');
    $this->get(route('sactus', ['aba' => 'historico']))->assertOk()->assertSee('Nenhum pedido');
    $this->get(route('sactus', ['aba' => 'robo']))->assertOk()->assertSee('Desligado neste servidor');
});

test('lista os clientes com nome do Help e filtra os que falharam', function () {
    $ok = criarClienteHelpDesk(['razao_social' => 'MERCADO BOM']);
    $falhou = criarClienteHelpDesk(['razao_social' => 'MERCADO RUIM']);
    coletaSactus($ok);
    coletaSactus($falhou, ['status' => 'N', 'erro' => 'NORMAL 1.1.1.1:22 => falha na conexao/handshake', 'conteudo' => '', 'json' => '']);

    Livewire::test(Clientes::class)
        ->assertSee(['MERCADO BOM', 'MERCADO RUIM'])
        ->set('filtro', 'falhas')
        ->assertSee('MERCADO RUIM')
        ->assertDontSee('MERCADO BOM')
        ->call('selecionar', $falhou)
        ->assertSee('falha na conexao/handshake');
});

test('as senhas do cfg vão mascaradas e só admin vê o botão de mostrar', function () {
    $cliente = criarClienteHelpDesk();
    coletaSactus($cliente);

    Livewire::test(Clientes::class)->call('selecionar', $cliente)
        ->assertSee('jm_database_password')
        ->assertDontSee('segredo-do-banco')
        ->assertDontSee('mostrar')
        ->set('detalhe', 'arquivo')
        ->assertSee('jm_database_password=••••••')
        ->assertDontSee('segredo-do-banco');

    Livewire::test(Clientes::class)->call('selecionar', $cliente)->call('revelar', 'jm_database_password')->assertForbidden();
});

test('admin mostra uma senha depois de confirmar a própria senha do Help, e fica registrado', function () {
    tornarAdmin($this->usuario);
    $cliente = criarClienteHelpDesk();
    coletaSactus($cliente);

    $tela = Livewire::test(Clientes::class)->call('selecionar', $cliente)
        ->call('revelar', 'jm_database_password')
        ->assertSet('pedindoSenha', true)
        ->assertDontSee('segredo-do-banco')
        ->set('senhaConfirmacao', 'errada')
        ->call('confirmarSenha')
        ->assertHasErrors(['senhaConfirmacao'])
        ->set('senhaConfirmacao', 'segredo')
        ->call('confirmarSenha')
        ->assertSet('pedindoSenha', false)
        ->assertSee('segredo-do-banco');

    expect(Auditoria::query()->where('acao', 'sactus.revelar_flag')->first())
        ->usuario->toBe('Maria da Silva')
        ->fk_c_cliente->toBe($cliente)
        ->detalhes->toBe(['flag' => 'jm_database_password']);

    // Dentro dos 15 minutos não pede de novo
    $tela->set('detalhe', 'arquivo')->call('revelarArquivo')->assertSet('pedindoSenha', false)->assertSee('jm_database_password=segredo-do-banco');
});

test('o navegador não consegue trocar a ação que roda depois da senha', function () {
    tornarAdmin($this->usuario);

    Livewire::test(Clientes::class)->set('acaoPendente', 'aplicarAgora');
})->throws(CannotUpdateLockedPropertyException::class);

test('pedido de edição: desligado avisa; ligado registra com o nome de quem pediu (sem o valor na auditoria)', function () {
    tornarAdmin($this->usuario);
    session()->put('walle.senha_confirmada_em', now()->getTimestamp());
    $cliente = criarClienteHelpDesk();
    coletaSactus($cliente);

    Livewire::test(Clientes::class)->call('selecionar', $cliente)
        ->set('edFlag', 'debug')->set('edValor', 'S')
        ->call('pedirEdicao')
        ->assertSee('O Sactus do walle está desligado');
    expect(SactusCfgEdicao::count())->toBe(0);

    config(['walle.sactus.ativo' => true]);
    $tela = Livewire::test(Clientes::class)->call('selecionar', $cliente)->set('detalhe', 'pedidos')
        ->set('edFlag', '2invalida')->call('pedirEdicao')->assertHasErrors(['edFlag'])
        ->set('edFlag', 'jm_database_password')->set('edValor', 'nova-senha')->call('pedirEdicao')
        ->assertSee('registrado');

    expect(SactusCfgEdicao::query()->first())
        ->only(['fk_c_cliente', 'tipo_edicao', 'flag', 'valor', 'status', 'solicitante'])
        ->toBe(['fk_c_cliente' => $cliente, 'tipo_edicao' => 'ADICIONAR', 'flag' => 'jm_database_password', 'valor' => 'nova-senha', 'status' => 'PENDENTE', 'solicitante' => 'Maria da Silva']);
    expect(json_encode(Auditoria::query()->where('acao', 'sactus.pedir_edicao')->first()->detalhes))->not->toContain('nova-senha');

    // Valor sensível também vai mascarado no histórico da tela; e o mesmo pedido aberto não duplica
    $tela->assertDontSee('nova-senha')
        ->set('edFlag', 'JM_DATABASE_PASSWORD')->set('edValor', 'x')->call('pedirEdicao')
        ->assertSee('Já existe um pedido aberto para essa flag');
});

test('ações e "aplicar agora" de admin vão para a fila', function () {
    Queue::fake();
    tornarAdmin($this->usuario);
    session()->put('walle.senha_confirmada_em', now()->getTimestamp());
    config(['walle.sactus.ativo' => true]);
    $cliente = criarClienteSactus();
    coletaSactus($cliente);

    Livewire::test(Clientes::class)->call('selecionar', $cliente)
        ->call('pedirAcao', 'YUM_UPDATE')->assertSee('na fila')
        ->call('aplicarAgora')->assertSee('Cliente na fila do robô');

    Queue::assertPushed(ExecutarAcaoSactus::class);
    Queue::assertPushed(ProcessarClienteSactus::class, fn ($job) => $job->clienteId === $cliente);
});

test('o verificador confere a flag em todos e não mostra valor sensível', function () {
    $a = criarClienteHelpDesk(['razao_social' => 'A']);
    $b = criarClienteHelpDesk(['razao_social' => 'B']);
    $c = criarClienteHelpDesk(['razao_social' => 'C']);
    coletaSactus($a, ['conteudo' => cfgSactus(['debug' => ' s '])]);
    coletaSactus($b);
    coletaSactus($c, ['status' => 'N', 'conteudo' => '', 'json' => '']);

    $tela = Livewire::test(Verificador::class)->set('flag', 'debug')->set('valor', 'S');
    expect(collect($tela->get('resultado'))->pluck('situacao', 'nome')->all())->toBe(['B' => 'diferente', 'C' => 'sem_coleta', 'A' => 'confere']);

    $tela->set('flag', 'jm_database_password')->set('valor', 'segredo-do-banco')
        ->assertDontSee('segredo-do-banco');
    expect(collect($tela->get('resultado'))->pluck('situacao', 'nome')->all())->toMatchArray(['A' => 'confere', 'B' => 'confere']);
});

test('o robô mostra o plano lido do Help e o "rodar agora" é de admin com senha', function () {
    Queue::fake();
    criarClienteSactus(['atualizar_erp' => 'S']);
    criarClienteSactus(['pasta_multiempresa' => 'S']);
    config(['walle.sactus.erp.senha' => 'x']);

    Livewire::test(Robo::class)->assertSee(['Servidores na coleta', 'Atualizam o ERP'])->assertSet('aviso', null);

    config(['walle.sactus.ativo' => true]);
    Livewire::test(Robo::class)->call('rodarAgora')->assertForbidden();

    tornarAdmin($this->usuario);
    Livewire::test(Robo::class)->call('rodarAgora')->assertSet('pedindoSenha', true)
        ->set('senhaConfirmacao', 'segredo')->call('confirmarSenha')
        ->assertSee('Ciclo pedido');
    Queue::assertPushed(RodarCicloSactus::class);
});
