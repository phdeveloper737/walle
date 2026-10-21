<?php

use App\Models\Usuario;

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

test('quem já está logado não vê a tela de entrar', function () {
    $this->get(route('entrar'))->assertRedirect(route('painel'));
});
