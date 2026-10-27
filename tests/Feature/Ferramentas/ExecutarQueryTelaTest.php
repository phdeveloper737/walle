<?php

use App\Contingencias\Cliente\ConectorDeCliente;
use App\Livewire\Ferramentas;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

function entrarParaExecutarQuery(bool $admin = true, bool $senhaConfirmada = true): void
{
    $usuario = Usuario::find(criarUsuarioHelpDesk());
    test()->actingAs($usuario);
    config(['walle.admins' => $admin ? [$usuario->id] : [], 'walle.confirmar_senha_minutos' => 15]);

    if ($senhaConfirmada) {
        session()->put('walle.senha_confirmada_em', now()->getTimestamp());
    }
}

function tabelaDaTelaDeQuery(int $quantidade): void
{
    Schema::create('produto_tela_teste', function ($table) {
        $table->temporary();
        $table->integer('id');
        $table->string('descricao');
    });

    for ($i = 1; $i <= $quantidade; $i++) {
        DB::table('produto_tela_teste')->insert(['id' => $i, 'descricao' => "ITEM TELA {$i}"]);
    }
}

test('aba inválida volta para queries e a aba executar existe', function () {
    entrarParaExecutarQuery();

    expect(Ferramentas::ABAS)->toHaveKeys(['querys', 'relatorio', 'executar']);

    Livewire::test(Ferramentas::class, ['aba' => 'qualquer'])->assertSet('aba', 'querys');
});

test('admin vê o formulário e executa SELECT com a tabela mostrando as linhas', function () {
    entrarParaExecutarQuery();
    $cliente = criarClienteHelpDesk();
    tabelaDaTelaDeQuery(2);
    servidorDoClienteNoBancoDeTestes();

    Livewire::test(Ferramentas::class, ['aba' => 'executar'])
        ->assertOk()
        ->assertSee('Executar')
        ->set('clienteExecucao', $cliente)
        ->set('sqlExecucao', "SELECT id, descricao FROM produto_tela_teste\nORDER BY id")
        ->call('executarQuery')
        ->assertSet('pedindoSenha', false)
        ->assertSee('ITEM TELA 1')
        ->assertSee('ITEM TELA 2')
        ->assertSee('descricao');
});

test('resultado truncado é sinalizado', function () {
    entrarParaExecutarQuery();
    config(['walle.ferramentas.query_limite_linhas' => 2]);
    $cliente = criarClienteHelpDesk();
    tabelaDaTelaDeQuery(4);
    servidorDoClienteNoBancoDeTestes();

    Livewire::test(Ferramentas::class, ['aba' => 'executar'])
        ->set('clienteExecucao', $cliente)
        ->set('sqlExecucao', 'SELECT id FROM produto_tela_teste ORDER BY id')
        ->call('executarQuery')
        ->assertSee('Mostrando as primeiras 2 linhas');
});

test('escrita mostra as linhas afetadas', function () {
    entrarParaExecutarQuery();
    $cliente = criarClienteHelpDesk();
    tabelaDaTelaDeQuery(3);
    servidorDoClienteNoBancoDeTestes();

    Livewire::test(Ferramentas::class, ['aba' => 'executar'])
        ->set('clienteExecucao', $cliente)
        ->set('sqlExecucao', "UPDATE produto_tela_teste SET descricao = 'X'")
        ->call('executarQuery')
        ->assertSee('3 linhas afetadas');
});

test('erro do cliente aparece como aviso de erro', function () {
    entrarParaExecutarQuery();
    $cliente = criarClienteHelpDesk();
    servidorDoClienteNoBancoDeTestes();

    Livewire::test(Ferramentas::class, ['aba' => 'executar'])
        ->set('clienteExecucao', $cliente)
        ->set('sqlExecucao', 'SELECT * FROM tabela_inexistente_tela')
        ->call('executarQuery')
        ->assertSee('tabela_inexistente_tela');
});

test('quem não é admin não vê o formulário e recebe 403 ao executar', function () {
    entrarParaExecutarQuery(admin: false);
    $cliente = criarClienteHelpDesk();
    $this->mock(ConectorDeCliente::class)->shouldNotReceive('conectar');

    Livewire::test(Ferramentas::class, ['aba' => 'executar'])
        ->assertOk()
        ->assertDontSee('wire:submit', false)
        ->assertSee('Só admins')
        ->set('clienteExecucao', $cliente)
        ->set('sqlExecucao', 'SELECT 1')
        ->call('executarQuery')
        ->assertForbidden();
});

test('sem senha confirmada abre a janela de senha, nada é enviado e o SQL é mantido', function () {
    entrarParaExecutarQuery(senhaConfirmada: false);
    $cliente = criarClienteHelpDesk();
    $this->mock(ConectorDeCliente::class)->shouldNotReceive('conectar');

    Livewire::test(Ferramentas::class, ['aba' => 'executar'])
        ->set('clienteExecucao', $cliente)
        ->set('sqlExecucao', 'SELECT 1')
        ->call('executarQuery')
        ->assertSet('pedindoSenha', true)
        ->assertSet('sqlExecucao', 'SELECT 1');
});

test('depois de confirmar a senha a query digitada roda', function () {
    entrarParaExecutarQuery(senhaConfirmada: false);
    $cliente = criarClienteHelpDesk();
    tabelaDaTelaDeQuery(1);
    servidorDoClienteNoBancoDeTestes();

    Livewire::test(Ferramentas::class, ['aba' => 'executar'])
        ->set('clienteExecucao', $cliente)
        ->set('sqlExecucao', 'SELECT descricao FROM produto_tela_teste')
        ->call('executarQuery')
        ->assertSet('pedindoSenha', true)
        ->set('senhaConfirmacao', 'segredo')
        ->call('confirmarSenha')
        ->assertSee('ITEM TELA 1');
});

test('o SQL digitado não vai para a URL', function () {
    foreach (['sqlExecucao', 'clienteExecucao'] as $propriedade) {
        $atributos = (new ReflectionProperty(Ferramentas::class, $propriedade))->getAttributes(Livewire\Attributes\Url::class);

        expect($atributos)->toBe([]);
    }
});

test('sem resultado ainda mostra o estado vazio', function () {
    entrarParaExecutarQuery();

    Livewire::test(Ferramentas::class, ['aba' => 'executar'])->assertSee('Nenhuma consulta executada');
});
