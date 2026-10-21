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
        ->assertSee(['Aplicação', 'Banco walle', 'Valkey', 'Help Desk', 'Filas (Horizon)', 'Agendador']);
});

test('a batida do agendador aparece mesmo vindo como texto do Valkey', function () {
    // O Valkey devolve números como texto; com is_int() a tela dizia "sem batida"
    Cache::put('walle:agendador:batida', (string) now()->subSeconds(30)->getTimestamp(), 600);

    $this->get(route('saude'))->assertOk()->assertSee('Última batida há');
});

test('quem já está logado não vê a tela de entrar', function () {
    $this->get(route('entrar'))->assertRedirect(route('painel'));
});
