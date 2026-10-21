<?php

use App\Livewire\Entrar;
use App\Models\AcessoUsuario;
use App\Models\Usuario;
use Livewire\Livewire;

test('quem não está logado vai para a tela de entrar', function () {
    $this->get('/')->assertRedirect(route('entrar'));
    $this->get('/saude')->assertRedirect(route('entrar'));
    $this->get('/horizon')->assertRedirect(route('entrar'));
});

test('a tela de entrar abre', function () {
    $this->get(route('entrar'))->assertOk()->assertSee('Use o mesmo usuário e senha do Help Desk.');
});

test('entra com o usuário e a senha do Help Desk e registra o acesso', function () {
    $id = criarUsuarioHelpDesk();

    Livewire::test(Entrar::class)
        ->set('usuario', ' maria ')
        ->set('senha', 'segredo ')
        ->call('entrar')
        ->assertHasNoErrors()
        ->assertRedirect(route('painel'));

    $this->assertAuthenticatedAs(Usuario::find($id));

    $acesso = AcessoUsuario::where('fk_a_user', $id)->sole();
    expect($acesso->data_hora_login)->not->toBeNull()
        ->and($acesso->data_hora_logout)->toBeNull();
});

test('senha errada não entra', function () {
    criarUsuarioHelpDesk();

    Livewire::test(Entrar::class)
        ->set('usuario', 'maria')
        ->set('senha', 'errada')
        ->call('entrar')
        ->assertHasErrors('login')
        ->assertSet('senha', '');

    $this->assertGuest();
    expect(AcessoUsuario::count())->toBe(0);
});

test('usuário deletado no Help Desk não entra', function () {
    criarUsuarioHelpDesk(['deletado' => 'S']);

    Livewire::test(Entrar::class)
        ->set('usuario', 'maria')
        ->set('senha', 'segredo')
        ->call('entrar')
        ->assertHasErrors('login');

    $this->assertGuest();
});

test('com dois cadastros iguais entra com o id mais alto, como no sistema antigo', function () {
    criarUsuarioHelpDesk();
    $maisNovo = criarUsuarioHelpDesk(['name' => 'Maria (novo cadastro)']);

    Livewire::test(Entrar::class)
        ->set('usuario', 'maria')
        ->set('senha', 'segredo')
        ->call('entrar');

    $this->assertAuthenticatedAs(Usuario::find($maisNovo));
});

test('bloqueia depois de 5 tentativas erradas', function () {
    criarUsuarioHelpDesk();

    foreach (range(1, Entrar::MAX_TENTATIVAS) as $tentativa) {
        Livewire::test(Entrar::class)->set('usuario', 'maria')->set('senha', 'errada')->call('entrar');
    }

    // Mesmo com a senha certa, fica bloqueado até passar o minuto
    Livewire::test(Entrar::class)
        ->set('usuario', 'maria')
        ->set('senha', 'segredo')
        ->call('entrar')
        ->assertHasErrors('login')
        ->assertSee('Muitas tentativas');

    $this->assertGuest();
});

test('campos vazios pedem usuário e senha', function () {
    Livewire::test(Entrar::class)
        ->call('entrar')
        ->assertHasErrors(['usuario' => 'required', 'senha' => 'required']);
});

test('sair encerra a sessão e grava a hora de saída', function () {
    $id = criarUsuarioHelpDesk();

    Livewire::test(Entrar::class)->set('usuario', 'maria')->set('senha', 'segredo')->call('entrar');

    $this->post(route('sair'))->assertRedirect(route('entrar'));

    $this->assertGuest();
    expect(AcessoUsuario::where('fk_a_user', $id)->sole()->data_hora_logout)->not->toBeNull();
});

test('o a_user do Help Desk é somente leitura no walle', function () {
    $usuario = Usuario::find(criarUsuarioHelpDesk());

    expect(fn () => $usuario->forceFill(['name' => 'Outro nome'])->save())->toThrow(LogicException::class)
        ->and(fn () => $usuario->delete())->toThrow(LogicException::class);
});
