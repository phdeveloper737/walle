<?php

use App\Livewire\Contingencias;
use App\Livewire\Contingencias\Dashboard;
use App\Models\Usuario;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(Usuario::find(criarUsuarioHelpDesk()));
    Cache::flush();
});

/** Linha do painel_contingency_crawler: um card do dashboard. */
function semearCardPainelDashboard(int $cliente, int $total, ?Carbon $data = null): void
{
    DB::table('painel_contingency_crawler')->insert([
        'fk_c_cliente' => $cliente,
        'total_cliente' => $total,
        'total_bot' => $total + 1,
        'total_sactus' => 2,
        'total_versatus' => max(0, $total - 2),
        'data' => $data ?? now(),
    ]);
}

/** Linha do informacao_tecnica_cleantaxbot (Help Desk de teste) para o filtro de tipo. */
function semearEmissaoCuponsDashboard(int $cliente, string $emissao): void
{
    DB::connection('help')->table('informacao_tecnica_cleantaxbot')->insert([
        'fk_c_cliente' => $cliente,
        'emissao_cupons' => $emissao,
    ]);
}

test('a aba dashboard abre para quem está logado e mostra um card por cliente do painel', function () {
    $alfa = criarClienteHelpDesk(['razao_social' => 'MERCADO ALFA']);
    $beta = criarClienteHelpDesk(['razao_social' => 'MERCADO BETA']);
    semearCardPainelDashboard($alfa, 7);
    semearCardPainelDashboard($beta, 0);

    $this->get(route('contingencias', ['aba' => 'dashboard']))
        ->assertOk()
        ->assertSee('MERCADO ALFA')
        ->assertSee('MERCADO BETA')
        ->assertSee('12.345.678/0001-90')
        ->assertSee('ID: '.$alfa)
        ->assertSee('Tabela Cliente')
        ->assertSee('Tabela Motivos')
        ->assertSee('Total Sactus')
        ->assertSee('Total Versatus')
        ->assertSee('Tem contingência')
        ->assertSee('Atualizando a cada 10 s');
});

test('os cards vêm do maior total para o menor, com empate pelo id do cliente', function () {
    $pequeno = criarClienteHelpDesk(['razao_social' => 'MERCADO PEQUENO']);
    $grande = criarClienteHelpDesk(['razao_social' => 'MERCADO GRANDE']);
    $empateA = criarClienteHelpDesk(['razao_social' => 'MERCADO EMPATE A']);
    $empateB = criarClienteHelpDesk(['razao_social' => 'MERCADO EMPATE B']);
    semearCardPainelDashboard($pequeno, 1);
    semearCardPainelDashboard($grande, 9);
    semearCardPainelDashboard($empateA, 4);
    semearCardPainelDashboard($empateB, 4);

    Livewire::test(Dashboard::class)
        ->assertSeeInOrder(['MERCADO GRANDE', 'MERCADO EMPATE A', 'MERCADO EMPATE B', 'MERCADO PEQUENO']);

    $cards = Livewire::test(Dashboard::class)->instance()->clientes;
    expect(array_column($cards, 'id'))->toBe([$grande, $empateA, $empateB, $pequeno]);
    expect($cards[0])->toMatchArray([
        'totalCliente' => 9, 'totalBot' => 10, 'totalSactus' => 2, 'totalVersatus' => 7,
    ]);
});

test('o nome cai da razão social para o nome fantasia e depois para Cliente #id', function () {
    $razao = criarClienteHelpDesk(['razao_social' => 'RAZAO SOCIAL LTDA', 'nome_fantasia' => 'FANTASIA']);
    $fantasia = criarClienteHelpDesk(['razao_social' => '', 'nome_fantasia' => 'SO FANTASIA']);
    $semNome = criarClienteHelpDesk(['razao_social' => '', 'nome_fantasia' => '']);
    semearCardPainelDashboard($razao, 1);
    semearCardPainelDashboard($fantasia, 1);
    semearCardPainelDashboard($semNome, 1);

    $nomes = collect(Livewire::test(Dashboard::class)->instance()->clientes)->pluck('nome', 'id');

    expect($nomes[$razao])->toBe('RAZAO SOCIAL LTDA')
        ->and($nomes[$fantasia])->toBe('SO FANTASIA')
        ->and($nomes[$semNome])->toBe('Cliente #'.$semNome);
});

test('o relógio fica na cor primária até 5 minutos e na cor de erro depois disso', function () {
    $this->travelTo(Carbon::parse('2026-11-07 12:00:00'));
    $recente = criarClienteHelpDesk(['razao_social' => 'MERCADO RECENTE']);
    $antigo = criarClienteHelpDesk(['razao_social' => 'MERCADO ANTIGO']);
    semearCardPainelDashboard($recente, 1, now()->subMinutes(4));
    semearCardPainelDashboard($antigo, 1, now()->subMinutes(6));

    $teste = Livewire::test(Dashboard::class);
    $cards = collect($teste->instance()->clientes)->keyBy('id');

    expect($cards[$recente]['recente'])->toBeTrue()
        ->and($cards[$antigo]['recente'])->toBeFalse()
        ->and(str_contains($teste->html(), 'text-primary'))->toBeTrue()
        ->and(str_contains($teste->html(), 'text-error'))->toBeTrue()
        ->and(str_contains($teste->html(), '07/11/2026 11:54'))->toBeTrue();
});

test('o filtro de tipo usa o emissao_cupons e cliente sem linha só aparece em Todos', function () {
    $sim = criarClienteHelpDesk(['razao_social' => 'MERCADO SIM']);
    $nao = criarClienteHelpDesk(['razao_social' => 'MERCADO NAO']);
    $semLinha = criarClienteHelpDesk(['razao_social' => 'MERCADO SEM LINHA']);
    semearCardPainelDashboard($sim, 1);
    semearCardPainelDashboard($nao, 1);
    semearCardPainelDashboard($semLinha, 1);
    semearEmissaoCuponsDashboard($sim, 'S');
    semearEmissaoCuponsDashboard($nao, 'N');

    Livewire::test(Dashboard::class)
        ->set('tipo', 'S')
        ->assertSee('MERCADO SIM')
        ->assertDontSee('MERCADO NAO')
        ->assertDontSee('MERCADO SEM LINHA')
        ->set('tipo', 'N')
        ->assertSee('MERCADO NAO')
        ->assertDontSee('MERCADO SIM')
        ->assertDontSee('MERCADO SEM LINHA')
        ->set('tipo', '')
        ->assertSee('MERCADO SIM')
        ->assertSee('MERCADO NAO')
        ->assertSee('MERCADO SEM LINHA');
});

test('a busca acha por nome, CNPJ sem pontuação e id', function () {
    $alfa = criarClienteHelpDesk(['razao_social' => 'MERCADO ALFA', 'cnpj' => '11.222.333/0001-44']);
    $beta = criarClienteHelpDesk(['razao_social' => 'MERCADO BETA', 'cnpj' => '55.666.777/0001-88']);
    semearCardPainelDashboard($alfa, 1);
    semearCardPainelDashboard($beta, 1);

    Livewire::test(Dashboard::class)->set('busca', 'alfa')
        ->assertSee('MERCADO ALFA')
        ->assertDontSee('MERCADO BETA');

    Livewire::test(Dashboard::class)->set('busca', '11222333000144')
        ->assertSee('MERCADO ALFA')
        ->assertDontSee('MERCADO BETA');

    Livewire::test(Dashboard::class)->set('busca', '#'.$beta)
        ->assertSee('MERCADO BETA')
        ->assertDontSee('MERCADO ALFA');
});

test('os contadores OK e Tem contingência filtram, e clicar de novo ou em Todos limpa', function () {
    $com = criarClienteHelpDesk(['razao_social' => 'MERCADO COM']);
    $okA = criarClienteHelpDesk(['razao_social' => 'MERCADO OK A']);
    $okB = criarClienteHelpDesk(['razao_social' => 'MERCADO OK B']);
    semearCardPainelDashboard($com, 5);
    semearCardPainelDashboard($okA, 0);
    semearCardPainelDashboard($okB, 0);

    $teste = Livewire::test(Dashboard::class)
        ->assertSee('3 de 3 clientes')
        ->call('alternarSituacao', 'ok')
        ->assertSet('situacao', 'ok')
        ->assertSee('MERCADO OK A')
        ->assertSee('MERCADO OK B')
        ->assertDontSee('MERCADO COM')
        ->assertSee('2 de 3 clientes')
        ->call('alternarSituacao', 'ok')
        ->assertSet('situacao', '')
        ->assertSee('MERCADO COM')
        ->call('alternarSituacao', 'contingencia')
        ->assertSet('situacao', 'contingencia')
        ->assertSee('MERCADO COM')
        ->assertDontSee('MERCADO OK A')
        ->call('limparFiltros')
        ->assertSet('situacao', '')
        ->assertSet('busca', '')
        ->assertSet('tipo', '')
        ->assertSee('MERCADO OK A');

    expect($teste->instance()->resumo)->toBe(['ok' => 2, 'contingencia' => 1, 'total' => 3]);
});

test('valores inválidos na URL voltam ao padrão', function () {
    Livewire::withQueryParams(['situacao' => 'xyz', 'tipo' => 'X'])
        ->test(Dashboard::class)
        ->assertSet('situacao', '')
        ->assertSet('tipo', '')
        ->assertSet('busca', '');
});

test('o card inteiro linka para o relatório de contingência do cliente', function () {
    $cliente = criarClienteHelpDesk(['razao_social' => 'MERCADO LINK']);
    semearCardPainelDashboard($cliente, 2);

    $url = e(route('relatorios', ['aba' => 'contingencia', 'cliente' => $cliente]));

    Livewire::test(Dashboard::class)->assertSeeHtml('href="'.$url.'"');
});

test('com o Help Desk fora do ar os cards seguem com Cliente #id, sem CNPJ e com o aviso de erro', function () {
    $cliente = criarClienteHelpDesk(['razao_social' => 'MERCADO ALFA']);
    semearCardPainelDashboard($cliente, 3);
    $bancoHelp = config('database.connections.help.database');

    try {
        config(['database.connections.help.database' => 'nao_existe_help']);
        DB::purge('help');

        Livewire::test(Dashboard::class)
            ->assertOk()
            ->assertSee('Cliente #'.$cliente)
            ->assertSee('O Help Desk (192.168.10.254) não respondeu')
            ->assertDontSee('MERCADO ALFA')
            ->assertDontSee('12.345.678/0001-90')
            ->assertSee('Filtro de kits indisponível sem o Help Desk.')
            ->set('tipo', 'S')
            ->assertSee('Cliente #'.$cliente);
    } finally {
        config(['database.connections.help.database' => $bancoHelp]);
        DB::purge('help');
    }
});

test('painel vazio e filtro sem resultado mostram o estado vazio', function () {
    Livewire::test(Dashboard::class)
        ->assertSee('Nenhuma coleta do robô ainda');

    $cliente = criarClienteHelpDesk(['razao_social' => 'MERCADO ALFA']);
    semearCardPainelDashboard($cliente, 1);

    Livewire::test(Dashboard::class)
        ->set('busca', 'nao existe')
        ->assertSee('Nenhum cliente com estes filtros');
});

test('com o robô desligado a aba segue o mesmo aviso da aba Robô', function () {
    config(['walle.contingencias.ativo' => false]);
    $cliente = criarClienteHelpDesk(['razao_social' => 'MERCADO ALFA']);
    semearCardPainelDashboard($cliente, 1);

    Livewire::test(Dashboard::class)
        ->assertSee('O robô de contingências está desligado')
        ->assertSee('MERCADO ALFA');
});

test('a aba dashboard é filha da tela de contingências e a aba robô segue intacta', function () {
    Livewire::test(Contingencias::class, ['aba' => 'dashboard'])
        ->assertSet('aba', 'dashboard')
        ->assertSeeHtml('aba-dashboard');

    Livewire::test(Contingencias::class, ['aba' => 'xyz'])->assertSet('aba', 'clientes');
});
