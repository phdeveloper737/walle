<?php

use App\Models\RoboConfigHistorico;
use App\Models\Usuario;
use App\Support\ConfiguracaoRobo;

/*
| Testes de fumaça no navegador: cada tela abre sem erro de JavaScript e os fluxos que dependem do
| Livewire/Alpine no navegador (abas, janela de senha) funcionam. A lógica fica nos testes de Feature.
*/

function entrarNoNavegador(bool $admin = false): Usuario
{
    $usuario = Usuario::find(criarUsuarioHelpDesk());
    test()->actingAs($usuario);
    config(['walle.admins' => $admin ? [$usuario->id] : []]);

    return $usuario;
}

test('a tela de entrar abre sem erro de JavaScript', function () {
    visit('/entrar')
        ->assertSee('Entrar')
        ->assertNoJavaScriptErrors();
});

test('as telas internas abrem sem erro de JavaScript', function () {
    entrarNoNavegador();

    visit([
        '/',
        '/contingencias',
        '/autorizadas',
        '/sactus',
        '/relatorios',
        '/sentinela',
        '/clean-tax',
        '/ferramentas',
        '/crontab',
        '/saude',
    ])->assertNoJavaScriptErrors();
});

test('as abas de configuração e histórico de um robô trocam pela URL', function () {
    entrarNoNavegador();

    visit('/contingencias?aba=configuracao')
        ->assertSee('Execução do robô')
        ->assertSee('Parâmetros')
        ->click('Histórico')
        ->assertSee('Alterações de configuração')
        ->assertQueryStringHas('aba', 'historico')
        ->assertNoJavaScriptErrors();
});

test('ligar a execução local pede a senha numa janela e grava no histórico', function () {
    config(['walle.autorizadas.ativo' => true]);
    entrarNoNavegador(admin: true);

    visit('/autorizadas?aba=configuracao')
        ->press('Ligar execução local')
        ->assertSee('Confirme que é você')
        ->type('senhaConfirmacao', 'segredo')
        ->press('Confirmar')
        ->assertSee('Execução local ligada')
        ->assertNoJavaScriptErrors();

    expect(ConfiguracaoRobo::ativoLocal('autorizadas'))->toBeTrue()
        ->and(RoboConfigHistorico::query()->where('robo', 'autorizadas')->where('parametro', 'ativo')->count())->toBe(1);
});

test('quem não é admin não vê o botão de ligar o robô', function () {
    entrarNoNavegador(admin: false);

    visit('/autorizadas?aba=configuracao')
        ->assertSee('Execução do robô')
        ->assertDontSee('Ligar execução local')
        ->assertNoJavaScriptErrors();
});
