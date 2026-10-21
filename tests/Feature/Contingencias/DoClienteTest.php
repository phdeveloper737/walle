<?php

use App\Livewire\Contingencias\DoCliente;
use App\Models\Usuario;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(Usuario::find(criarUsuarioHelpDesk()));
});

test('mostra as contingências pendentes com motivo, itens e inconsistências', function () {
    $cliente = criarClienteHelpDesk();
    criarContingenciaSactus(['cupom' => '555', 'pdv' => '3'], [['plu_sdesc' => 'REFRIGERANTE 2L', 'cst_icms' => '60', 'cfop' => '5102']]);
    servidorDoClienteNoBancoDeTestes();

    Livewire::withoutLazyLoading()->test(DoCliente::class, ['clienteId' => $cliente])
        ->assertSee('1 pendentes')
        ->assertSee('Cupom 555')
        ->assertSee('PDV 3')
        ->assertSee('Rejeição: CFOP não permitido para o CST informado')
        ->assertSee('REFRIGERANTE 2L')
        ->assertSee('1 item com inconsistência')
        ->assertSee('CST 60 com CFOP 5102: o esperado é 5405.');
});

test('filtra por produto e troca para as autorizadas', function () {
    $cliente = criarClienteHelpDesk();
    criarContingenciaSactus(['cupom' => '601'], [['plu_sdesc' => 'CAFE 500G']]);
    $autorizada = criarContingenciaSactus(['cupom' => '602'], [['plu_sdesc' => 'ACUCAR 1KG']]);
    autorizarNaSefaz($cliente, $autorizada, now()->toDateString());
    servidorDoClienteNoBancoDeTestes();

    Livewire::withoutLazyLoading()->test(DoCliente::class, ['clienteId' => $cliente])
        ->assertSee('CAFE 500G')
        ->assertDontSee('ACUCAR 1KG')
        ->set('filtro', 'nada disso')
        ->assertSee('Nada com esse filtro')
        ->set('filtro', '')
        ->set('aba', 'autorizadas')
        ->assertSee($autorizada)
        ->assertSee('Autorizadas (1)');
});

test('pagina de 20 em 20', function () {
    $cliente = criarClienteHelpDesk();
    foreach (range(1, 25) as $cupom) {
        criarContingenciaVersatus($cliente, ['cupom' => (string) (1000 + $cupom), 'id_venda' => $cupom, 'data_hora' => now()->subMinutes($cupom)]);
    }
    servidorDoClienteForaDoAr();

    Livewire::withoutLazyLoading()->test(DoCliente::class, ['clienteId' => $cliente])
        ->assertSee('1–20 de 25')
        ->assertSee('Cupom 1001')
        ->assertDontSee('Cupom 1025')
        ->call('irParaPagina', 2)
        ->assertSee('21–25 de 25')
        ->assertSee('Cupom 1025');
});

test('avisa quando o servidor do cliente não responde', function () {
    $cliente = criarClienteHelpDesk();
    servidorDoClienteForaDoAr();

    Livewire::withoutLazyLoading()->test(DoCliente::class, ['clienteId' => $cliente])
        ->assertSee('Sactus: O servidor do cliente não respondeu')
        ->assertSee('Nenhuma contingência pendente');
});
