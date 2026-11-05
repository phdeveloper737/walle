<?php

use App\Livewire\Contingencias;
use App\Livewire\Contingencias\Robo;
use App\Models\CicloContingencias;
use App\Models\ColetaContingencias;
use App\Models\Usuario;
use Illuminate\Support\Carbon;
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
        ->assertSeeHtml('Falhou')
        ->assertSee('.env desligado');
});

function semearSoColeta(int $cliente, string $status, ?string $erro = null, ?Carbon $inicio = null): void
{
    $ciclo = CicloContingencias::create(['iniciado_em' => now()]);
    ColetaContingencias::create([
        'ciclo_id' => $ciclo->id, 'fk_c_cliente' => $cliente, 'iniciado_em' => $inicio ?? now(),
        'terminado_em' => ($inicio ?? now())->copy()->addSeconds(75), 'status' => $status, 'erro' => $erro,
    ]);
}

test('cliente que só tem coleta com falha aparece com o botão Falhou, sem totais, e entra no KPI de falhas', function () {
    $falho = criarClienteHelpDesk(['razao_social' => 'MERCADO FALHO']);
    $bom = criarClienteHelpDesk(['razao_social' => 'MERCADO BOM']);
    semearColetaDoPainel($bom, 'sucesso', 3);
    semearSoColeta($falho, 'falha', 'O servidor do cliente não respondeu.');

    Livewire::test(Robo::class)
        ->assertSeeInOrder(['MERCADO FALHO', 'MERCADO BOM'])
        ->assertSeeHtml('wire:click="verColetas('.$falho.')"')
        ->assertSee('Falhou')
        ->assertSee('—')
        ->assertSet('clienteColetas', null)
        ->assertViewHas('ligado')
        ->tap(fn ($t) => expect($t->instance()->resumo)->toMatchArray(['clientes' => 2, 'falhas' => 1, 'com' => 1]));
});

test('clicar na situação abre a janela com o erro; fecharColetas fecha', function () {
    $cliente = criarClienteHelpDesk(['razao_social' => 'MERCADO FALHO']);
    semearSoColeta($cliente, 'falha', 'O servidor do cliente não respondeu.');

    Livewire::test(Robo::class)
        ->assertDontSee('Coletas de MERCADO FALHO')
        ->call('verColetas', $cliente)
        ->assertSet('clienteColetas', $cliente)
        ->assertSee('Coletas de MERCADO FALHO')
        ->assertSee('O servidor do cliente não respondeu.')
        ->call('fecharColetas')
        ->assertSet('clienteColetas', null)
        ->assertDontSee('Coletas de MERCADO FALHO');
});

test('a janela lista no máximo 20 coletas, a mais recente primeiro, com início, duração e situação', function () {
    $cliente = criarClienteHelpDesk(['razao_social' => 'MERCADO LONGO']);
    foreach (range(1, 25) as $i) {
        semearSoColeta($cliente, 'sucesso', null, Carbon::parse('2026-01-01 08:00:00')->addDays($i));
    }

    $teste = Livewire::test(Robo::class)->call('verColetas', $cliente);

    expect($teste->instance()->coletasDoCliente)->toHaveCount(20);
    $html = $teste->html();
    expect(str_contains($html, '1 min 15 s'))->toBeTrue('duração');
    expect(strpos($html, '26/01/2026 08:00:00') < strpos($html, '25/01/2026 08:00:00'))->toBeTrue('ordem');
    expect(str_contains($html, '07/01/2026 08:00:00'))->toBeTrue('vigésima');
    expect(str_contains($html, '06/01/2026 08:00:00'))->toBeFalse('vigésima primeira');
});

test('cliente sem coleta mostra o estado vazio na janela e valor inválido é ignorado', function () {
    $cliente = criarClienteHelpDesk(['razao_social' => 'MERCADO NOVO']);

    Livewire::test(Robo::class)
        ->call('verColetas', $cliente)
        ->assertSee('Nenhuma coleta deste cliente')
        ->call('fecharColetas')
        ->call('verColetas', 0)
        ->assertSet('clienteColetas', null)
        ->call('verColetas', -5)
        ->assertSet('clienteColetas', null);
});

test('sem coletas mostra o estado vazio e aba inválida volta para clientes', function () {
    Livewire::test(Robo::class)->assertSee('Nenhuma coleta ainda');
    Livewire::test(Contingencias::class, ['aba' => 'xyz'])->assertSet('aba', 'clientes');
});
