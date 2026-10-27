<?php

namespace App\Livewire;

use App\Rag\IndiceConhecimento;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/** Explora os aprendizados do RAG local; tela nova, sem equivalente no 220. */
#[Title('Cérebro do RAG')]
class Conhecimento extends Component
{
    #[Url(except: '')]
    public string $agente = '';

    #[Url(except: '')]
    public string $categoria = '';

    #[Url(except: '')]
    public string $busca = '';

    #[Url(except: 0)]
    public int $memoria = 0;

    #[Url(except: 1)]
    public int $pagina = 1;

    public function boot(): void
    {
        abort_unless(Gate::allows('admin'), 403);
    }

    public function updated(string $propriedade): void
    {
        if (in_array($propriedade, ['agente', 'categoria', 'busca'], true)) {
            $this->memoria = 0;
            $this->pagina = 1;
        }
    }

    public function irParaPagina(int $pagina): void
    {
        abort_unless(Gate::allows('admin'), 403);
        $this->pagina = max(1, $pagina);
        $this->memoria = 0;
    }

    /** @return array<string, mixed> */
    #[Computed]
    public function dados(): array
    {
        abort_unless(Gate::allows('admin'), 403);
        try {
            return app(IndiceConhecimento::class)->carregar($this->agente, $this->categoria, $this->busca, $this->pagina);
        } catch (Throwable) {
            return ['erro' => true];
        }
    }

    public function render(): View
    {
        abort_unless(Gate::allows('admin'), 403);

        return view('livewire.conhecimento', ['dados' => $this->dados(), 'categorias' => IndiceConhecimento::CATEGORIAS]);
    }
}
