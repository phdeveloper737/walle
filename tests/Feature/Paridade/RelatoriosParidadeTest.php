<?php

use App\Livewire\Relatorios;
use App\Models\InformacaoTecnicaPainelContingencias;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(Usuario::find(criarUsuarioHelpDesk()));
    $this->travelTo('2026-10-21 12:00:00');
});

test('clientes de consultoria mantêm total do 220 para amostra capturada', function () {
    $amostra = json_decode(file_get_contents(base_path('tests/Fixtures/legado/relatorios/clientes-consultoria-ativos.json')), true, flags: JSON_THROW_ON_ERROR);

    foreach ($amostra['clientes'] as $cliente) {
        DB::connection('help')->table('c_cliente')->insert($cliente);
    }

    $componente = Livewire::test(Relatorios::class, ['aba' => 'clientes'])->assertOk();

    expect($componente->get('clientes'))->toHaveCount($amostra['total_220'], "220: {$amostra['total_220']}; walle: ".count($componente->get('clientes')));
    $componente->assertSee('MERCADO ALVORADA')->assertSee('ATACADO HORIZONTE');
});

test('histórico de contingência NFC-e mantém linhas e total do 220 para amostra capturada', function () {
    $amostra = json_decode(file_get_contents(base_path('tests/Fixtures/legado/relatorios/contingencia-nfce-semana.json')), true, flags: JSON_THROW_ON_ERROR);

    foreach ($amostra['historico_220'] as $indice => $linha) {
        DB::table('history_contingencia')->insert([
            'fk_c_cliente' => $amostra['cliente'],
            'serie' => $linha['serie'],
            'numero' => $linha['numero'],
            'chave' => str_pad((string) ($indice + 1), 44, '0', STR_PAD_LEFT),
            'motivo' => $indice < 2 ? str_replace('  %s', ' [nItem: %s]', $linha['motivo']) : $linha['motivo'],
            'software' => $linha['software'],
            'data_fiscal_xml' => $linha['data'],
            'data_clique' => '2026-10-21 07:27:41',
        ]);
    }

    $componente = Livewire::test(Relatorios::class, ['aba' => 'contingencia', 'cliente' => $amostra['cliente']])->assertOk();
    $historico = $componente->get('historico');

    expect($historico)->toHaveCount($amostra['total_220'], "220: {$amostra['total_220']}; walle: ".count($historico));
    expect(array_map(fn (object $linha): array => [
        'serie' => $linha->serie,
        'numero' => $linha->numero,
        'motivo' => str_replace(['(', ')', '[', ']', 'nItem:'], '', trim((string) $linha->motivo)),
        'software' => $linha->software,
        'data' => substr((string) $linha->data_fiscal_xml, 0, 10),
    ], $historico))->toBe(array_map(fn (array $linha): array => [
        'serie' => $linha['serie'],
        'numero' => $linha['numero'],
        'motivo' => $linha['motivo'],
        'software' => $linha['software'],
        'data' => substr($linha['data'], 0, 10),
    ], $amostra['historico_220']));
});

test('motivos aplicam data_clique, desvio aprovado da 6.1, para amostra capturada', function () {
    $amostra = json_decode(file_get_contents(base_path('tests/Fixtures/legado/relatorios/motivos-semana-com-contingencias.json')), true, flags: JSON_THROW_ON_ERROR);

    foreach ($amostra['clientes'] as $cliente) {
        InformacaoTecnicaPainelContingencias::query()->create([
            'id' => $cliente['id'],
            'fk_c_cliente' => $cliente['id'],
            'emissao_cupons' => $cliente['emissao_cupons'],
        ]);
    }

    foreach ($amostra['linhas'] as $linha) {
        DB::table('history_contingencia')->insert($linha);
    }

    $componente = Livewire::test(Relatorios::class, ['aba' => 'motivos'])
        ->set('de', $amostra['periodo']['de'])
        ->set('ate', $amostra['periodo']['ate'])
        ->set('tipo', $amostra['tipo'])
        ->assertOk();

    expect($componente->get('motivos'))->toBe($amostra['totais_220_no_recorte_com_desvio_aprovado']);
    expect(array_sum(array_column($componente->get('motivos'), 'total')))->toBe($amostra['total_220_no_recorte_com_desvio_aprovado']);
});

test('semana sem contingências mostra zero como 220', function () {
    $amostra = [
        'de' => '2026-10-12',
        'ate' => '2026-10-18',
        'total_220' => 0,
    ];

    $componente = Livewire::test(Relatorios::class, ['aba' => 'motivos'])
        ->set('de', $amostra['de'])
        ->set('ate', $amostra['ate'])
        ->assertOk()
        ->assertSee('Nenhum motivo de contingência no período');

    expect($componente->get('motivos'))->toHaveCount($amostra['total_220'], "220: {$amostra['total_220']}; walle: ".count($componente->get('motivos')));
});
