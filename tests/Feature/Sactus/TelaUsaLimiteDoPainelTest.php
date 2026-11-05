<?php

use App\Livewire\Sactus\Robo;
use App\Models\CicloSactus;
use App\Models\Usuario;
use App\Support\ConfiguracaoRobo;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

test('a aba Robô considera em andamento o ciclo dentro do limite_ciclo_minutos configurado no painel', function () {
    $this->travelTo(Carbon::parse('2026-10-21 10:00:00'));
    $this->actingAs(Usuario::find(criarUsuarioHelpDesk()));
    CicloSactus::create(['iniciado_em' => now()->subMinutes(100)]);

    expect(Livewire::test(Robo::class)->instance()->cicloAtual)->toBeNull('com o padrão o ciclo de 100 min está vencido');

    ConfiguracaoRobo::gravar('sactus', 'limite_ciclo_minutos', 120, 'teste');

    expect(Livewire::test(Robo::class)->instance()->cicloAtual)->not->toBeNull();
});
