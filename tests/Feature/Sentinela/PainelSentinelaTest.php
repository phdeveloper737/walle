<?php

use App\Livewire\Sentinela;
use App\Models\PainelSentinela;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

function criarColetaSentinela(int $cliente, array $dados = []): PainelSentinela
{
    return PainelSentinela::create(array_merge([
        'fk_c_cliente' => $cliente,
        'status' => 'S',
        'hostname' => 'srv-'.$cliente,
        'disco_pct' => 41,
        'mem_pct' => 40.0,
        'sactus_rodando' => 'S',
        'sactus_md5_ok' => 'S',
        'data' => now(),
    ], $dados));
}

function criarNomeNoPainelSentinela(int $cliente, string $razao): void
{
    DB::table('informacao_tecnica_painel_contingencias')->insert(['fk_c_cliente' => $cliente, 'razao_social' => $razao]);
}

beforeEach(function () {
    $this->actingAs(Usuario::find(criarUsuarioHelpDesk()));
});

test('a tela mostra cada cliente com o estado coletado e filtra pelo cliente da URL', function () {
    criarNomeNoPainelSentinela(101, 'MERCADO UM LTDA');
    criarColetaSentinela(101);
    criarColetaSentinela(102, ['status' => 'N', 'erro' => 'Sem resposta do servidor']);

    Livewire::test(Sentinela::class)
        ->assertOk()
        ->assertSee('MERCADO UM LTDA')
        ->assertSee('Cliente #102')
        ->assertSee('Sem resposta do servidor')
        ->assertSee('Respondendo')
        ->assertSee('Sem resposta');

    Livewire::withQueryParams(['cliente' => 102])->test(Sentinela::class)
        ->assertSee('Cliente #102')
        ->assertDontSee('MERCADO UM LTDA');
});

test('cliente inexistente na URL mostra o vazio, sem erro', function () {
    criarColetaSentinela(101);

    Livewire::withQueryParams(['cliente' => 999])->test(Sentinela::class)
        ->assertOk()
        ->assertSee('Nenhum cliente encontrado');
});

test('avisa quando o robô está desligado', function () {
    config(['walle.sentinela.ativo' => false]);

    Livewire::test(Sentinela::class)->assertSee('desligado');
});
