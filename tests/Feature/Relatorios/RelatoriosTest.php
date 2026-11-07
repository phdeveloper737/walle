<?php

use App\Livewire\Relatorios;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(Usuario::find(criarUsuarioHelpDesk()));
});

/**
 * Contingência no histórico com motivo e data do clique, de um cliente do dataserver no Help Desk.
 * $emissaoCupons null = cliente sem linha no cleantaxbot; $noDataserver false = cliente fora do dataserver.
 */
function semearMotivo(int $clienteId, string $motivo, string $dataClique, ?string $emissaoCupons = 'N', bool $noDataserver = true): void
{
    if ($noDataserver && ! DB::connection('help')->table('informacao_tecnica_dataserver')->where('fk_c_cliente', $clienteId)->exists()) {
        DB::connection('help')->table('informacao_tecnica_dataserver')->insert(['fk_c_cliente' => $clienteId]);

        if ($emissaoCupons !== null) {
            DB::connection('help')->table('informacao_tecnica_cleantaxbot')->insert(['fk_c_cliente' => $clienteId, 'emissao_cupons' => $emissaoCupons]);
        }
    }

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

test('o ranking vem dos clientes do dataserver no Help Desk, mesmo com o painel do robô vazio', function () {
    expect(DB::table('informacao_tecnica_painel_contingencias')->count())->toBe(0);
    semearMotivo(10, 'Duplicidade', '2026-10-20 10:00:00');

    $componente = Livewire::test(Relatorios::class, ['aba' => 'motivos'])->set('de', '2026-10-19')->set('ate', '2026-10-25');

    expect($componente->get('motivos'))->toBe([['motivo' => 'DUPLICIDADE', 'total' => 1]]);
});

test('o tipo filtra pelo emissao_cupons do cleantaxbot; sem linha fica fora de N e S e entra em todos; fora do dataserver nunca entra', function () {
    semearMotivo(1, 'Motivo n', '2026-10-20 10:00:00', 'N');
    semearMotivo(2, 'Motivo s', '2026-10-20 10:00:00', 'S');
    semearMotivo(3, 'Motivo sem cleantax', '2026-10-20 10:00:00', null);
    semearMotivo(4, 'Motivo fora do dataserver', '2026-10-20 10:00:00', 'N', false);

    $motivos = fn (string $tipo): array => array_column(
        Livewire::test(Relatorios::class, ['aba' => 'motivos'])->set('de', '2026-10-19')->set('ate', '2026-10-25')->set('tipo', $tipo)->get('motivos'),
        'motivo',
    );

    expect($motivos('N'))->toBe(['MOTIVO N']);
    expect($motivos('S'))->toBe(['MOTIVO S']);
    expect($motivos(''))->toEqualCanonicalizing(['MOTIVO N', 'MOTIVO S', 'MOTIVO SEM CLEANTAX']);
});

test('com o Help Desk fora do ar a aba motivos mostra o erro, e não o vazio, sem quebrar', function () {
    semearMotivo(1, 'Duplicidade', '2026-10-20 10:00:00');
    $bancoHelp = config('database.connections.help.database');

    try {
        config(['database.connections.help.database' => 'nao_existe_help']);
        DB::purge('help');

        Livewire::test(Relatorios::class, ['aba' => 'motivos'])
            ->set('de', '2026-10-19')
            ->set('ate', '2026-10-25')
            ->assertOk()
            ->assertSee('O Help Desk (192.168.10.254) não respondeu')
            ->assertDontSee('Nenhum motivo de contingência no período');
    } finally {
        config(['database.connections.help.database' => $bancoHelp]);
        DB::purge('help');
    }
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

test('selecionar um motivo lista os clientes com o total de cada um, ordenados, e a soma bate com a linha do ranking', function () {
    semearMotivo(1, 'Rejeicao: CST invalido (nItem:1)', '2026-10-19 08:00:00');
    semearMotivo(1, 'rejeicao: cst invalido [nItem:2]', '2026-10-19 09:00:00');
    semearMotivo(2, 'Rejeicao: CST invalido nItem:3', '2026-10-20 10:00:00');
    semearMotivo(3, 'Rejeicao: CST invalido', '2026-10-21 11:00:00');

    $componente = Livewire::test(Relatorios::class, ['aba' => 'motivos'])
        ->set('de', '2026-10-19')
        ->set('ate', '2026-10-25');

    $motivoRanking = $componente->get('motivos')[0];
    expect($motivoRanking)->toBe(['motivo' => 'REJEICAO: CST INVALIDO', 'total' => 4]);

    $componente->assertSee('Ver clientes de REJEICAO: CST INVALIDO');

    $componente->call('selecionarMotivo', 'REJEICAO: CST INVALIDO')
        ->assertSet('motivo', 'REJEICAO: CST INVALIDO');

    $clientes = $componente->get('clientesDoMotivo');
    expect($clientes)->toBe([
        ['cliente' => 1, 'nome' => 'Cliente #1', 'cnpj' => '', 'total' => 2],
        ['cliente' => 2, 'nome' => 'Cliente #2', 'cnpj' => '', 'total' => 1],
        ['cliente' => 3, 'nome' => 'Cliente #3', 'cnpj' => '', 'total' => 1],
    ]);

    $soma = array_sum(array_column($clientes, 'total'));
    expect($soma)->toBe($motivoRanking['total']);
});

test('o detalhe do motivo ignora registros fora do periodo, de outro motivo ou de cliente fora do tipo escolhido', function () {
    semearMotivo(1, 'Duplicidade', '2026-10-19 08:00:00'); // dentro
    semearMotivo(1, 'Duplicidade', '2026-10-26 08:00:00'); // fora do período (depois)
    semearMotivo(1, 'Duplicidade', '2026-10-18 23:59:59'); // fora do período (antes)
    semearMotivo(1, 'Outro motivo', '2026-10-20 08:00:00'); // outro motivo
    semearMotivo(2, 'Duplicidade', '2026-10-20 09:00:00', 'S'); // tipo S (filtro tipo é N)
    semearMotivo(1, 'Duplicidade (nItem:2)', '2026-10-21 08:00:00'); // dentro (variante)

    $componente = Livewire::test(Relatorios::class, ['aba' => 'motivos'])
        ->set('de', '2026-10-19')
        ->set('ate', '2026-10-25')
        ->set('tipo', 'N')
        ->call('selecionarMotivo', 'DUPLICIDADE');

    expect($componente->get('clientesDoMotivo'))->toBe([
        ['cliente' => 1, 'nome' => 'Cliente #1', 'cnpj' => '', 'total' => 2],
    ]);
});

test('o nome do cliente cai para nome fantasia e depois para Cliente #id, e cliente sem CNPJ aparece', function () {
    $c1 = criarClienteHelpDesk(['razao_social' => 'EMPRESA RAZAO SOCIAL LTDA', 'nome_fantasia' => 'FANTASIA A', 'cnpj' => '11.111.111/0001-11']);
    $c2 = criarClienteHelpDesk(['razao_social' => '', 'nome_fantasia' => 'LOJA FANTASIA B', 'cnpj' => '22.222.222/0001-22']);
    $c3 = criarClienteHelpDesk(['razao_social' => '', 'nome_fantasia' => '', 'cnpj' => '']);

    semearMotivo($c1, 'Duplicidade', '2026-10-20 08:00:00');
    semearMotivo($c2, 'Duplicidade', '2026-10-20 09:00:00');
    semearMotivo($c3, 'Duplicidade', '2026-10-20 10:00:00');

    foreach ([$c1, $c2, $c3] as $id) {
        DB::connection('help')->table('informacao_tecnica_cleantaxbot')->insert(['fk_c_cliente' => $id, 'emissao_cupons' => 'N']);
    }

    $componente = Livewire::test(Relatorios::class, ['aba' => 'motivos'])
        ->set('de', '2026-10-19')
        ->set('ate', '2026-10-25')
        ->call('selecionarMotivo', 'DUPLICIDADE');

    $clientes = $componente->get('clientesDoMotivo');
    expect($clientes)->toEqualCanonicalizing([
        ['cliente' => $c1, 'nome' => 'EMPRESA RAZAO SOCIAL LTDA', 'cnpj' => '11.111.111/0001-11', 'total' => 1],
        ['cliente' => $c2, 'nome' => 'LOJA FANTASIA B', 'cnpj' => '22.222.222/0001-22', 'total' => 1],
        ['cliente' => $c3, 'nome' => "Cliente #{$c3}", 'cnpj' => '', 'total' => 1],
    ]);

    $componente->assertSee(route('relatorios', ['aba' => 'contingencia', 'cliente' => $c1]))
        ->assertSee(route('relatorios', ['aba' => 'contingencia', 'cliente' => $c3]));
});

test('trocar periodo ou tipo limpa o motivo e o motivo aceita valor inicial na URL', function () {
    semearMotivo(1, 'Duplicidade', '2026-10-20 08:00:00');

    Livewire::test(Relatorios::class, ['aba' => 'motivos', 'motivo' => 'duplicidade (nItem:9)', 'de' => '2026-10-19', 'ate' => '2026-10-25'])
        ->assertSet('motivo', 'DUPLICIDADE')
        ->assertSee('Cliente #1');

    $componente = Livewire::test(Relatorios::class, ['aba' => 'motivos', 'motivo' => 'DUPLICIDADE', 'de' => '2026-10-19', 'ate' => '2026-10-25'])
        ->assertSet('motivo', 'DUPLICIDADE');

    $componente->set('de', '2026-10-01')->assertSet('motivo', '');

    $componente->call('selecionarMotivo', 'DUPLICIDADE')->assertSet('motivo', 'DUPLICIDADE');
    $componente->set('tipo', 'S')->assertSet('motivo', '');

    $componente->call('selecionarMotivo', 'DUPLICIDADE')->assertSet('motivo', 'DUPLICIDADE');
    $componente->set('ate', '2026-10-30')->assertSet('motivo', '');
});

test('com o Help Desk fora do ar o detalhe do motivo mostra o erro sem quebrar a aba', function () {
    semearMotivo(1, 'Duplicidade', '2026-10-20 10:00:00');
    $bancoHelp = config('database.connections.help.database');

    try {
        config(['database.connections.help.database' => 'nao_existe_help']);
        DB::purge('help');

        Livewire::test(Relatorios::class, ['aba' => 'motivos', 'motivo' => 'DUPLICIDADE', 'de' => '2026-10-19', 'ate' => '2026-10-25'])
            ->assertOk()
            ->assertSee('O Help Desk (192.168.10.254) não respondeu e os clientes deste motivo não puderam ser carregados.');
    } finally {
        config(['database.connections.help.database' => $bancoHelp]);
        DB::purge('help');
    }
});

test('motivo sem clientes mostra o estado vazio no detalhe', function () {
    Livewire::test(Relatorios::class, ['aba' => 'motivos', 'motivo' => 'INEXISTENTE', 'de' => '2026-10-19', 'ate' => '2026-10-25'])
        ->assertOk()
        ->assertSee('Nenhum cliente com este motivo no período');
});
