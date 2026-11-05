<?php

use App\Models\CicloContingencias;
use App\Models\ColetaContingencias;
use App\Models\Usuario;

test('na aba robô, clicar em Falhou abre a janela de coletas e Escape fecha', function () {
    test()->actingAs(Usuario::find(criarUsuarioHelpDesk()));
    $cliente = criarClienteHelpDesk(['razao_social' => 'MERCADO NAVEGADOR']);
    $ciclo = CicloContingencias::create(['iniciado_em' => now()]);
    ColetaContingencias::create([
        'ciclo_id' => $ciclo->id, 'fk_c_cliente' => $cliente, 'iniciado_em' => now(),
        'terminado_em' => now(), 'status' => 'falha', 'erro' => 'O servidor do cliente não respondeu.',
    ]);

    visit('/contingencias?aba=robo')
        ->assertSee('MERCADO NAVEGADOR')
        ->click('Falhou')
        ->assertSee('Coletas de MERCADO NAVEGADOR')
        ->assertSee('O servidor do cliente não respondeu.')
        // Na janela, não no body: a camada da janela cobre o body e o Playwright espera para sempre por ele.
        ->keys('[role="dialog"]', 'Escape')
        ->assertDontSee('Coletas de MERCADO NAVEGADOR')
        ->assertNoJavaScriptErrors();
});
