<?php

use App\Concorrentes\AnaliseConcorrentes;
use App\Livewire\Concorrentes;
use App\Models\Usuario;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(Usuario::find(criarUsuarioHelpDesk()));
});

test('a tela de concorrentes abre pelo menu no resumo', function () {
    $this->get(route('concorrentes'))
        ->assertOk()
        ->assertSee(['Análise de 23/10/2026', 'Conclusões', 'Nenhuma NFC-e fica para trás.', 'Próximos passos']);
});

test('cada aba mostra a sua parte da análise', function () {
    Livewire::test(Concorrentes::class)
        ->set('aba', 'concorrentes')
        ->assertSee(['Competi Sistemas', 'Atua em Goiás', 'Fraquezas'])
        ->set('aba', 'matriz')
        ->assertSee(['PDV Linux', 'lacuna', 'Onde a JM ganha'])
        ->set('aba', 'oportunidades')
        ->assertSee(['Contingência Zero', 'Fazer já', 'D1 · Prova social']);
});

test('aba que não existe volta para o resumo', function () {
    Livewire::withQueryParams(['aba' => 'xpto'])
        ->test(Concorrentes::class)
        ->assertSet('aba', 'resumo');

    Livewire::test(Concorrentes::class)
        ->set('aba', 'xpto')
        ->assertSet('aba', 'resumo');
});

test('sem o arquivo da análise a tela mostra o vazio', function () {
    app()->instance(AnaliseConcorrentes::class, new AnaliseConcorrentes('/nao/existe.json'));

    $this->get(route('concorrentes'))
        ->assertOk()
        ->assertSee('Nenhuma análise de concorrentes ainda');
});

test('o arquivo da análise está completo e coerente', function () {
    // O squad regrava o JSON a cada rodada; este teste pega célula faltando ou situação inventada antes da tela quebrar
    $analise = app(AnaliseConcorrentes::class)->carregar();
    $colunas = array_keys($analise['matriz']['colunas']);
    $ids = array_column($analise['concorrentes'], 'id');

    expect($analise['concorrentes'])->toHaveCount(7)
        ->and(array_values(array_diff($colunas, ['jm'])))->toEqualCanonicalizing($ids);

    foreach ($analise['matriz']['linhas'] as $linha) {
        expect(array_keys($linha['valores']))->toEqualCanonicalizing($colunas, $linha['capacidade']);

        foreach ($linha['valores'] as $celula) {
            expect(AnaliseConcorrentes::SITUACOES)->toHaveKey($celula['situacao']);
        }
    }

    foreach ($analise['oportunidades'] as $oportunidade) {
        expect(['alto', 'medio', 'baixo'])->toContain($oportunidade['impacto'], $oportunidade['esforco'])
            ->and(['quick win', 'aposta'])->toContain($oportunidade['tipo']);
    }
});

test('a célula da matriz separa a situação da nota', function () {
    $linha = collect(app(AnaliseConcorrentes::class)->carregar()['matriz']['linhas'])->firstWhere('capacidade', 'ERP para supermercado');

    expect($linha['valores']['competi'])->toBe(['situacao' => 'parcial', 'nota' => 'generalista'])
        ->and($linha['valores']['totvs'])->toBe(['situacao' => 'sim', 'nota' => null]);
});
