<?php

namespace App\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Autorizadas: o robô que confere na Sefaz as contingências já autorizadas, a análise da base
 * e a consulta de uma chave (substitui o autorizadas.dash, .analise e .debug do sistema antigo).
 */
#[Title('Autorizadas')]
class Autorizadas extends Component
{
    public const ABAS = [
        'robo' => ['rotulo' => 'Robô', 'icone' => 'smart_toy'],
        'analise' => ['rotulo' => 'Análise', 'icone' => 'monitoring'],
        'chave' => ['rotulo' => 'Consultar chave', 'icone' => 'key'],
    ];

    #[Url(except: 'robo')]
    public string $aba = 'robo';

    public function updatedAba(): void
    {
        if (! array_key_exists($this->aba, self::ABAS)) {
            $this->aba = 'robo';
        }
    }

    public function render(): View
    {
        return view('livewire.autorizadas');
    }
}
