<?php

use App\Contingencias\ListaDeClientes;
use App\Livewire\Contingencias;
use App\Models\Usuario;
use Illuminate\Database\QueryException;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(Usuario::find(criarUsuarioHelpDesk()));
});

test('a tela de contingências abre pelo menu', function () {
    criarClienteHelpDesk(['razao_social' => 'SUPERMERCADO ALFA']);

    $this->get(route('contingencias'))
        ->assertOk()
        ->assertSee('SUPERMERCADO ALFA')
        ->assertSee('Escolha um cliente');
});

test('lista só os clientes não deletados, sem o cliente 18, em ordem de razão social', function () {
    criarClienteHelpDesk(['razao_social' => 'ZETA MERCADO']);
    criarClienteHelpDesk(['razao_social' => 'ALFA MERCADO']);
    criarClienteHelpDesk(['razao_social' => 'MERCADO DELETADO', 'deletado' => 'S']);
    criarClienteHelpDesk(['id' => 18, 'razao_social' => 'CLIENTE OCULTO']);

    $nomes = array_map(fn ($c) => $c->razaoSocial, app(ListaDeClientes::class)->todos());

    expect($nomes)->toBe(['ALFA MERCADO', 'ZETA MERCADO']);
});

test('soma as contingências do PDV Linux e do Versatus', function () {
    criarClienteHelpDesk([], [], contingenciasLinux: 3, contingenciasVersatus: 4);

    expect(app(ListaDeClientes::class)->todos()[0]->totalContingencias)->toBe(7);
});

test('tipo de PDV e SRV LOCAL seguem as regras do sistema antigo', function () {
    criarClienteHelpDesk(['razao_social' => 'A VERSATUS'], ['servidor_multiempresa' => 'V']);
    criarClienteHelpDesk(['razao_social' => 'B SACTUS'], ['servidor_multiempresa' => 'S']);
    criarClienteHelpDesk(['razao_social' => 'C OUTRO'], ['servidor_multiempresa' => 'A']);
    criarClienteHelpDesk(['razao_social' => 'D LOCAL'], ['local_servidor' => 'EMPRESA', 'ip_externo_servidor' => '10.8.0.15', 'pasta_multiempresa' => 'N']);
    criarClienteHelpDesk(['razao_social' => 'E DOCKER'], ['local_servidor' => 'EMPRESA', 'ip_externo_servidor' => '10.8.0.16', 'pasta_multiempresa' => 'S']);

    $clientes = collect(app(ListaDeClientes::class)->todos())->keyBy('razaoSocial');

    expect($clientes['A VERSATUS']->tipoPdv)->toBe('VERSATUS')
        ->and($clientes['B SACTUS']->tipoPdv)->toBe('SACTUS')
        ->and($clientes['C OUTRO']->tipoPdv)->toBe('NÃO DEFINIDO')
        ->and($clientes['D LOCAL']->servidorLocal)->toBeTrue()
        ->and($clientes['E DOCKER']->servidorLocal)->toBeFalse()
        ->and($clientes['A VERSATUS']->servidorLocal)->toBeFalse();
});

test('busca por nome sem acento, por CNPJ com ou sem pontuação e por #id', function () {
    $padaria = criarClienteHelpDesk(['razao_social' => 'PADARIA SÃO JOÃO', 'cnpj' => '11.222.333/0001-44']);
    criarClienteHelpDesk(['razao_social' => 'AÇOUGUE BOI GORDO', 'cnpj' => '55.666.777/0001-88']);

    $componente = Livewire::test(Contingencias::class);

    foreach (['sao joao', 'Padaria', '11.222.333', '11222333', "#{$padaria}"] as $termo) {
        $componente->set('busca', $termo)
            ->assertSee('PADARIA SÃO JOÃO')
            ->assertDontSee('AÇOUGUE BOI GORDO');
    }

    $componente->set('busca', 'nada disso')->assertSee('Nenhum cliente encontrado');
});

test('filtra consultorias e outros', function () {
    criarClienteHelpDesk(['razao_social' => 'CLIENTE CONSULTORIA', 'consultoria' => 'S']);
    criarClienteHelpDesk(['razao_social' => 'CLIENTE COMUM', 'consultoria' => 'N']);

    Livewire::test(Contingencias::class)
        ->assertSee('Consultorias (1)')
        ->set('filtro', 'consultorias')
        ->assertSee('CLIENTE CONSULTORIA')->assertDontSee('CLIENTE COMUM')
        ->set('filtro', 'outros')
        ->assertSee('CLIENTE COMUM')->assertDontSee('CLIENTE CONSULTORIA')
        ->set('filtro', 'qualquer coisa')
        ->assertSet('filtro', 'todos');
});

test('escolher um cliente mostra o detalhe dele', function () {
    $id = criarClienteHelpDesk(['razao_social' => 'MERCADO BETA'], ['loja' => '007'], contingenciasLinux: 2);

    Livewire::test(Contingencias::class)
        ->call('selecionar', $id)
        ->assertSet('clienteId', $id)
        ->assertSee("Cliente #{$id}")
        ->assertSee('Loja 007')
        ->assertSee('Help Desk: 2 pendentes');
});

test('IP, porta e dados de acesso do cliente não vão para a tela', function () {
    $id = criarClienteHelpDesk([], ['ip_externo_servidor' => '200.99.88.77', 'porta_http' => '9123']);

    Livewire::test(Contingencias::class)
        ->call('selecionar', $id)
        ->assertDontSee('200.99.88.77')
        ->assertDontSee('9123');
});

test('avisa quando o Help Desk está fora do ar', function () {
    $this->mock(ListaDeClientes::class)
        ->shouldReceive('todos')
        ->andThrow(new QueryException('help', 'select 1', [], new PDOException('Connection refused')));

    Livewire::test(Contingencias::class)
        ->assertSee('Não foi possível consultar o Help Desk');
});
