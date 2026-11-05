<?php

use App\Livewire\Autorizadas\Analise;
use App\Livewire\Autorizadas\Robo;
use App\Models\CicloAutorizadas;
use App\Models\Usuario;
use App\Support\ConfiguracaoRobo;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(Usuario::find(criarUsuarioHelpDesk()));
});

test('a aba Robô mostra gravar_a_cada e intervalo_horas configurados no painel', function () {
    ConfiguracaoRobo::gravar('autorizadas', 'gravar_a_cada', 77, 'teste');
    ConfiguracaoRobo::gravar('autorizadas', 'intervalo_horas', 9, 'teste');

    CicloAutorizadas::create(['iniciado_em' => now()]);

    Livewire::test(Robo::class)
        ->assertSee('o número anda a cada 77 chaves gravadas')
        ->assertSee('sem consulta há 9 h');
});

test('a aba Análise mostra dias_retencao configurado no painel', function () {
    ConfiguracaoRobo::gravar('autorizadas', 'dias_retencao', 45, 'teste');

    Livewire::withoutLazyLoading()->test(Analise::class)->assertSee('elas ficam 45 dias');
});
