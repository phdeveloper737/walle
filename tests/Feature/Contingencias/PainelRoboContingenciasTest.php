<?php

use App\Livewire\Contingencias;
use App\Livewire\Contingencias\Robo;
use App\Models\CicloContingencias;
use App\Models\ColetaContingencias;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(Usuario::find(criarUsuarioHelpDesk()));
});

function semearColetaDoPainel(int $cliente, string $status, ?int $total, ?string $erro = null): void
{
    DB::table('painel_contingency_crawler')->insert([
        'fk_c_cliente' => $cliente, 'total_cliente' => $total, 'total_bot' => $total,
        'total_sactus' => $total === null ? null : 1, 'total_versatus' => $total === null ? null : max(0, $total - 1), 'data' => now(),
    ]);
    $ciclo = CicloContingencias::create(['iniciado_em' => now()]);
    ColetaContingencias::create([
        'ciclo_id' => $ciclo->id, 'fk_c_cliente' => $cliente, 'iniciado_em' => now(),
        'terminado_em' => now(), 'status' => $status, 'erro' => $erro, 'total_bot' => $total,
    ]);
}

test('a aba robô lista os clientes do painel com a última coleta', function () {
    config(['walle.contingencias.ativo' => true]);
    $alfa = criarClienteHelpDesk(['razao_social' => 'MERCADO ALFA']);
    $beta = criarClienteHelpDesk(['razao_social' => 'MERCADO BETA']);
    semearColetaDoPainel($alfa, 'sucesso', 5);
    semearColetaDoPainel($beta, 'sucesso', 0);

    $this->get(route('contingencias', ['aba' => 'robo']))
        ->assertOk()
        ->assertSee('MERCADO ALFA')
        ->assertSee('MERCADO BETA')
        ->assertSee('Com contingências')
        ->assertDontSee('WALLE_CONTINGENCIAS_ATIVO');
});

test('cliente com erro mostra a etiqueta de erro e o robô desligado mostra o aviso', function () {
    config(['walle.contingencias.ativo' => false]);
    $cliente = criarClienteHelpDesk(['razao_social' => 'MERCADO GAMA']);
    semearColetaDoPainel($cliente, 'falha', null, 'O servidor do cliente não respondeu.');

    Livewire::test(Robo::class)
        ->assertSee('MERCADO GAMA')
        ->assertSee('O servidor do cliente não respondeu.')
        ->assertSeeHtml('Falhou')
        ->assertSee('WALLE_CONTINGENCIAS_ATIVO=false');
});

test('sem coletas mostra o estado vazio e aba inválida volta para clientes', function () {
    Livewire::test(Robo::class)->assertSee('Nenhuma coleta ainda');
    Livewire::test(Contingencias::class, ['aba' => 'xyz'])->assertSet('aba', 'clientes');
});
