<?php

use App\Models\CicloContingencias;
use App\Models\ColetaContingencias;
use App\Models\PainelContingencyCrawler;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

test('um ciclo mantém as coletas e seus horários sem depender de cadastro no Help Desk', function () {
    $this->travelTo(Carbon::parse('2026-10-24 10:00:00'));
    $ciclo = CicloContingencias::create(['iniciado_em' => now(), 'elegiveis' => 2]);
    $coleta = $ciclo->coletas()->create([
        'fk_c_cliente' => 9101,
        'iniciado_em' => now(),
        'terminado_em' => now()->addSeconds(8),
        'status' => 'sucesso',
        'total_cliente' => 3,
        'total_bot' => 3,
        'total_sactus' => 2,
        'total_versatus' => 1,
    ]);

    $ciclo->update(['terminado_em' => now()->addSeconds(10), 'status' => 'concluido', 'coletados' => 1]);
    $ciclo->load('coletas');
    $coleta->load('ciclo');

    expect($ciclo->coletas->modelKeys())->toBe([$coleta->id]);
    expect($coleta->ciclo->id)->toBe($ciclo->id);
    expect($ciclo->terminado_em->toDateTimeString())->toBe('2026-10-24 10:00:10');
    expect($coleta->iniciado_em->toDateTimeString())->toBe('2026-10-24 10:00:00');
    expect($coleta->terminado_em->toDateTimeString())->toBe('2026-10-24 10:00:08');
    $this->assertDatabaseHas('contingencias_coletas', ['id' => $coleta->id, 'fk_c_cliente' => 9101, 'total_bot' => 3]);
});

test('o painel associa o último resultado do próprio cliente e preserva métricas na falha', function () {
    $painel = PainelContingencyCrawler::create([
        'fk_c_cliente' => 9101,
        'total_cliente' => 3,
        'total_bot' => 3,
        'total_sactus' => 2,
        'total_versatus' => 1,
        'data' => '2026-10-24 09:00:00',
    ]);
    $primeiroCiclo = CicloContingencias::create(['iniciado_em' => '2026-10-24 09:00:00']);
    $primeiroCiclo->coletas()->create([
        'fk_c_cliente' => 9101,
        'iniciado_em' => '2026-10-24 09:00:00',
        'status' => 'sucesso',
        'total_bot' => 3,
    ]);
    $segundoCiclo = CicloContingencias::create(['iniciado_em' => '2026-10-24 10:00:00']);
    $falha = $segundoCiclo->coletas()->create([
        'fk_c_cliente' => 9101,
        'iniciado_em' => '2026-10-24 10:00:00',
        'status' => 'falha',
        'erro' => 'Cliente indisponível.',
    ]);
    $segundoCiclo->coletas()->create([
        'fk_c_cliente' => 9102,
        'iniciado_em' => '2026-10-24 10:00:00',
        'status' => 'sucesso',
    ]);

    $painel->load('ultimaColeta', 'coletas');

    expect($painel->ultimaColeta->id)->toBe($falha->id);
    expect($painel->ultimaColeta->erro)->toBe('Cliente indisponível.');
    expect($painel->ultimaColeta->total_bot)->toBeNull();
    expect($painel->coletas)->toHaveCount(2);
    $this->assertDatabaseHas('painel_contingency_crawler', ['fk_c_cliente' => 9101, 'total_bot' => 3, 'data' => '2026-10-24 09:00:00']);

    $terceiroCiclo = CicloContingencias::create(['iniciado_em' => '2026-10-24 11:00:00']);
    $sucesso = $terceiroCiclo->coletas()->create([
        'fk_c_cliente' => 9101,
        'iniciado_em' => '2026-10-24 11:00:00',
        'status' => 'sucesso',
        'total_bot' => 0,
    ]);
    $painel->load('ultimaColeta');

    expect($painel->ultimaColeta->id)->toBe($sucesso->id);
    expect($painel->ultimaColeta->erro)->toBeNull();
    expect($painel->ultimaColeta->total_bot)->toBe(0);
    $this->assertDatabaseHas('contingencias_coletas', ['id' => $falha->id, 'erro' => 'Cliente indisponível.']);
});

test('o mesmo cliente não recebe duas coletas dentro do mesmo ciclo', function () {
    $ciclo = CicloContingencias::create(['iniciado_em' => '2026-10-24 10:00:00']);
    $dados = ['fk_c_cliente' => 9101, 'iniciado_em' => '2026-10-24 10:00:00'];
    $ciclo->coletas()->create($dados);

    expect(fn () => $ciclo->coletas()->create($dados))->toThrow(QueryException::class);

    expect(ColetaContingencias::where('ciclo_id', $ciclo->id)->count())->toBe(1);
});

test('um ciclo com coletas não pode ser apagado e perder seu histórico', function () {
    $ciclo = CicloContingencias::create(['iniciado_em' => '2026-10-24 10:00:00']);
    $coleta = $ciclo->coletas()->create(['fk_c_cliente' => 9101, 'iniciado_em' => '2026-10-24 10:00:00']);

    expect(fn () => $ciclo->delete())->toThrow(QueryException::class);

    $this->assertModelExists($ciclo);
    $this->assertModelExists($coleta);
});
