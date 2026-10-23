<?php

use App\Models\Usuario;

test('o painel tem o atalho para a landing page, abrindo em outra aba', function () {
    $this->actingAs(Usuario::find(criarUsuarioHelpDesk()));

    $html = $this->get(route('painel'))
        ->assertOk()
        ->assertSee('Landing page Fiscal em dia')
        ->getContent();

    expect($html)->toMatch('#<a href="'.preg_quote(route('landing.fiscal'), '#').'"[^>]*target="_blank"#');
});

test('a landing page abre como HTML para quem está logado', function () {
    $this->actingAs(Usuario::find(criarUsuarioHelpDesk()));

    $resposta = $this->get(route('landing.fiscal'))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/html; charset=UTF-8');

    expect(file_get_contents($resposta->baseResponse->getFile()->getPathname()))
        ->toContain('Fiscal em dia, do caixa ao SPED')
        ->toContain('wa.me/556239987100');
});

test('quem não está logado não vê a prévia da landing page', function () {
    $this->get(route('landing.fiscal'))->assertRedirect(route('entrar'));
});
