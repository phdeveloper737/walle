<?php

use App\Models\CicloContingencias;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

test('contingencias_coletas tem detalhe_tecnico JSON nulo logo depois de erro', function () {
    $colunas = Schema::getColumns('contingencias_coletas');
    $nomes = array_column($colunas, 'name');
    $coluna = collect($colunas)->firstWhere('name', 'detalhe_tecnico');

    expect($coluna)->not->toBeNull()
        ->and($coluna['type_name'])->toBe('json')
        ->and($coluna['nullable'])->toBeTrue()
        ->and($nomes[array_search('detalhe_tecnico', $nomes, true) - 1])->toBe('erro');
});

test('o detalhe técnico volta como array e fica nulo quando não informado', function () {
    $ciclo = CicloContingencias::create(['iniciado_em' => '2026-10-24 10:00:00']);
    $detalhe = ['host' => '10.0.0.5', 'porta' => 3306, 'excecao' => 'Connection refused'];

    $coleta = $ciclo->coletas()->create([
        'fk_c_cliente' => 9101,
        'iniciado_em' => '2026-10-24 10:00:00',
        'detalhe_tecnico' => $detalhe,
    ]);
    $semDetalhe = $ciclo->coletas()->create(['fk_c_cliente' => 9102, 'iniciado_em' => '2026-10-24 10:00:00']);

    expect($coleta->fresh()->detalhe_tecnico)->toBe($detalhe)
        ->and($semDetalhe->fresh()->detalhe_tecnico)->toBeNull();
});
