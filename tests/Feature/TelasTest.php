<?php

use App\Models\Usuario;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->actingAs(Usuario::find(criarUsuarioHelpDesk()));
});

test('o painel abre e cumprimenta pelo primeiro nome', function () {
    $this->get(route('painel'))->assertOk()->assertSee('Maria.');
});

test('a tela de saúde do sistema abre com todas as conferências', function () {
    $this->get(route('saude'))
        ->assertOk()
        ->assertSee(['Aplicação', 'Banco walle', 'Valkey', 'Help Desk', 'Filas (Horizon)', 'Agendador', 'Robô das Autorizadas', 'Desligado neste servidor']);
});

test('a batida do agendador aparece mesmo vindo como texto do Valkey', function () {
    // O Valkey devolve números como texto; com is_int() a tela dizia "sem batida"
    Cache::put('walle:agendador:batida', (string) now()->subSeconds(30)->getTimestamp(), 600);

    $this->get(route('saude'))->assertOk()->assertSee('Última batida há');
});

test('cada tela nova abre para o usuário logado', function (string $rota) {
    $this->get(route($rota))->assertOk();
})->with(['relatorios', 'sentinela', 'clean-tax', 'ferramentas', 'crontab']);

test('a saúde mostra os robôs novos desligados neste servidor', function () {
    config(['walle.contingencias.ativo' => false, 'walle.sentinela.ativo' => false, 'walle.cleantax.ativo' => false, 'walle.tarefas.ativo' => false]);

    $this->get(route('saude'))->assertOk()
        ->assertSee(['Robô das Contingências', 'Robô do Sentinela', 'Robô do CleanTax', 'Robô das Tarefas agendadas'])
        ->assertSee(['WALLE_CONTINGENCIAS_ATIVO=false', 'WALLE_SENTINELA_ATIVO=false', 'WALLE_CLEANTAX_ATIVO=false', 'WALLE_TAREFAS_ATIVO=false']);
});

test('o visitante é levado para a tela de entrar nas rotas novas', function (string $rota) {
    auth()->logout();

    $this->get(route($rota))->assertRedirect(route('entrar'));
})->with(['relatorios', 'sentinela', 'clean-tax', 'ferramentas', 'crontab']);

test('as telas de programação restritas abrem para admin', function (string $rota) {
    $usuario = auth()->user();
    config(['walle.admins' => [$usuario->id]]);

    $this->get(route($rota))->assertOk();
})->with(['informacoes-tecnicas', 'conversor']);

test('as telas de programação restritas recusam usuário comum', function (string $rota) {
    config(['walle.admins' => []]);

    $this->get(route($rota))->assertForbidden();
})->with(['informacoes-tecnicas', 'conversor']);

test('o visitante é levado para a tela de entrar nas telas de programação restritas', function (string $rota) {
    auth()->logout();

    $this->get(route($rota))->assertRedirect(route('entrar'));
})->with(['informacoes-tecnicas', 'conversor']);

test('o menu Programação só aparece para admin', function () {
    $usuario = auth()->user();

    config(['walle.admins' => []]);
    $this->get(route('painel'))->assertOk()->assertSee('Relatórios')->assertDontSee('Programação');

    config(['walle.admins' => [$usuario->id]]);
    $this->get(route('painel'))
        ->assertOk()
        ->assertSee('Programação')
        ->assertSee([route('crontab'), route('informacoes-tecnicas'), route('conversor')], false);
});

test('quem já está logado não vê a tela de entrar', function () {
    $this->get(route('entrar'))->assertRedirect(route('painel'));
});
