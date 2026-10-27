<?php

use App\Livewire\Relatorios;
use App\Models\InformacaoTecnicaPainelContingencias;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(Usuario::find(criarUsuarioHelpDesk()));
});

/** Contingência no histórico com motivo e data do clique, para um cliente que está no painel do robô. */
function semearMotivo(int $clienteId, string $motivo, string $dataClique, string $emissaoCupons = 'N'): void
{
    InformacaoTecnicaPainelContingencias::query()->firstOrCreate(
        ['id' => $clienteId],
        ['fk_c_cliente' => $clienteId, 'emissao_cupons' => $emissaoCupons],
    );

    DB::table('history_contingencia')->insert([
        'fk_c_cliente' => $clienteId,
        'chave' => str_pad((string) random_int(1, PHP_INT_MAX), 44, '0', STR_PAD_LEFT),
        'motivo' => $motivo,
        'data_clique' => $dataClique,
        'data_fiscal_xml' => $dataClique,
    ]);
}

test('a aba motivos agrupa e conta como o 220: corta em ( [ e NITEM, caixa alta e Sem comunicação unificada', function () {
    // Semana de segunda 2026-10-19 a domingo 2026-10-25
    semearMotivo(1, 'Rejeicao: CST invalido (nItem:1)', '2026-10-19 08:00:00');
    semearMotivo(1, 'rejeicao: cst invalido [nItem:2]', '2026-10-21 09:00:00');
    semearMotivo(2, 'Rejeicao: CST invalido nItem:3', '2026-10-25 23:30:00');
    semearMotivo(2, 'Sem comunicacao com o servidor X', '2026-10-22 10:00:00');
    semearMotivo(3, 'SEM COMUNICACAO COM A SEFAZ (timeout)', '2026-10-23 10:00:00');
    semearMotivo(3, 'Duplicidade', '2026-10-26 00:00:00'); // fora da semana
    semearMotivo(4, 'Rejeicao: CST invalido', '2026-10-20 10:00:00', 'S'); // kits automáticos: fora do filtro padrão

    $componente = Livewire::test(Relatorios::class, ['aba' => 'motivos'])
        ->set('de', '2026-10-19')
        ->set('ate', '2026-10-25')
        ->assertOk();

    expect($componente->get('motivos'))->toBe([
        ['motivo' => 'REJEICAO: CST INVALIDO', 'total' => 3],
        ['motivo' => 'SEM COMUNICACAO COM A SEFAZ', 'total' => 2],
    ]);

    $componente->assertSee('REJEICAO: CST INVALIDO')->assertSee('SEM COMUNICACAO COM A SEFAZ');

    $componente->set('tipo', '')->assertSet('tipo', '');
    expect($componente->get('motivos')[0])->toBe(['motivo' => 'REJEICAO: CST INVALIDO', 'total' => 4]);
});

test('periodo sem dados mostra o vazio e aba invalida volta para clientes', function () {
    Livewire::test(Relatorios::class, ['aba' => 'motivos'])
        ->set('de', '2020-01-01')
        ->set('ate', '2020-01-07')
        ->assertSee('Nenhum motivo de contingência no período');

    Livewire::test(Relatorios::class, ['aba' => 'inexistente'])->assertSet('aba', 'clientes');
    Livewire::test(Relatorios::class)->set('aba', 'xyz')->assertSet('aba', 'clientes');
});

test('a aba clientes lista os clientes de consultoria e a contingencia lista o historico do cliente', function () {
    $cliente = criarClienteHelpDesk(['razao_social' => 'MERCADO ALFA', 'consultoria' => 'S']);
    criarClienteHelpDesk(['razao_social' => 'MERCADO BETA', 'consultoria' => 'N']);
    semearMotivo($cliente, 'Duplicidade (nItem:1)', '2026-10-20 10:00:00');

    Livewire::test(Relatorios::class)
        ->assertOk()
        ->assertSee('MERCADO ALFA')
        ->assertDontSee('MERCADO BETA');

    Livewire::test(Relatorios::class, ['aba' => 'contingencia'])
        ->set('cliente', $cliente)
        ->assertSee('Duplicidade 1');

    Livewire::test(Relatorios::class, ['aba' => 'contingencia'])
        ->set('cliente', 999999)
        ->assertSee('Nenhuma contingência para este cliente');
});
