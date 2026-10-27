<?php

use App\Livewire\CleanTax;
use App\Models\PainelCleantaxWorker;
use App\Models\Usuario;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(Usuario::find(criarUsuarioHelpDesk()));
});

test('lista as execuções da iteração com início, fim e resultado', function () {
    PainelCleantaxWorker::create([
        'fk_c_cliente' => 201,
        'empresa' => 'MERCADO DOIS LTDA',
        'status_execucao' => 'success',
        'http_code' => 200,
        'mensagem' => 'Notas conferidas',
        'data_inicio' => '2026-10-27 08:00:00',
        'data_fim' => '2026-10-27 08:01:30',
        'data' => '2026-10-27 08:01:30',
    ]);
    PainelCleantaxWorker::create([
        'fk_c_cliente' => 202,
        'status_execucao' => 'error',
        'http_code' => 500,
        'mensagem' => 'Falha ao chamar https://api.exemplo/x?token=SEGREDO123 com Bearer ABCDEF',
        'data_inicio' => '2026-10-27 09:00:00',
        'data_fim' => '2026-10-27 09:00:05',
        'data' => '2026-10-27 09:00:05',
    ]);

    Livewire::test(CleanTax::class)
        ->assertOk()
        ->assertSee('MERCADO DOIS LTDA')
        ->assertSee('Cliente #202')
        ->assertSee('27/10/2026 08:00')
        ->assertSee('27/10/2026 08:01')
        ->assertSee('Sucesso')
        ->assertSee('Erro')
        ->assertSee('Notas conferidas')
        ->assertDontSee('SEGREDO123')
        ->assertDontSee('ABCDEF');
});

test('sem nenhuma execução mostra o vazio', function () {
    Livewire::test(CleanTax::class)
        ->assertOk()
        ->assertSee('Nenhuma execução ainda');
});

test('com o robô desligado a tela avisa', function () {
    config(['walle.cleantax.ativo' => false]);

    Livewire::test(CleanTax::class)->assertSee('desligado');
});
