<?php

namespace App\Livewire;

use App\Concorrentes\AnaliseConcorrentes;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Concorrentes: a análise competitiva da JM (resumo, fichas, matriz comparativa e oportunidades), só leitura. */
#[Title('Concorrentes')]
class Concorrentes extends Component
{
    public const ABAS = [
        'resumo' => ['rotulo' => 'Resumo', 'icone' => 'summarize'],
        'concorrentes' => ['rotulo' => 'Concorrentes', 'icone' => 'storefront'],
        'matriz' => ['rotulo' => 'Matriz', 'icone' => 'table_chart'],
        'oportunidades' => ['rotulo' => 'Oportunidades', 'icone' => 'lightbulb'],
    ];

    #[Url(except: 'resumo')]
    public string $aba = 'resumo';

    public function mount(): void
    {
        $this->updatedAba();
    }

    public function updatedAba(): void
    {
        if (! array_key_exists($this->aba, self::ABAS)) {
            $this->aba = 'resumo';
        }
    }

    public function render(AnaliseConcorrentes $analise): View
    {
        return view('livewire.concorrentes', ['analise' => $analise->carregar()]);
    }
}
