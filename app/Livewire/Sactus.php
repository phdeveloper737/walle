<?php

namespace App\Livewire;

use App\Support\ConfiguracaoRobo;
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
        'pedidos' => ['rotulo' => 'Pedidos e ações', 'icone' => 'edit_note'],
        'robo' => ['rotulo' => 'Robô', 'icone' => 'smart_toy'],
        'configuracao' => ['rotulo' => 'Configuração', 'icone' => 'tune'],
        'historico' => ['rotulo' => 'Histórico', 'icone' => 'history'],
    ];

    #[Url(except: 'clientes')]
    public string $aba = 'clientes';

    public function mount(): void
    {
        $this->updatedAba();
    }

    public function updatedAba(): void
    {
        if (! array_key_exists($this->aba, self::ABAS)) {
            $this->aba = 'clientes';
        }
    }

    public function render(): View
    {
        $motivo = ConfiguracaoRobo::motivoInativo('sactus');

        return view('livewire.sactus', [
            'ligado' => $motivo === null,
            'motivoDesligado' => match ($motivo) {
                'env' => 'WALLE_SACTUS_ATIVO=false',
                'local' => 'chave "ativo" desligada no painel',
                default => null,
            },
        ]);
    }
}
