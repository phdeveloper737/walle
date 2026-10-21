<?php

namespace App\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Sactus: o sactus.cfg coletado de cada cliente, o verificador de flags, os pedidos e ações
 * e o robô (substitui o sactus.dash.php do sistema antigo).
 */
#[Title('Sactus')]
class Sactus extends Component
{
    public const ABAS = [
        'clientes' => ['rotulo' => 'Clientes', 'icone' => 'dns'],
        'verificador' => ['rotulo' => 'Verificador', 'icone' => 'rule'],
        'historico' => ['rotulo' => 'Pedidos e ações', 'icone' => 'history'],
        'robo' => ['rotulo' => 'Robô', 'icone' => 'smart_toy'],
    ];

    #[Url(except: 'clientes')]
    public string $aba = 'clientes';

    public function updatedAba(): void
    {
        if (! array_key_exists($this->aba, self::ABAS)) {
            $this->aba = 'clientes';
        }
    }

    public function render(): View
    {
        return view('livewire.sactus');
    }
}
