<?php

namespace App\Livewire;

use App\Rag\IndiceConhecimento;
use App\Rag\MapaConhecimento;
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

    /** Similaridade mínima (em %) das ligações do mapa e das ramificações. */
    #[Url(except: 65)]
    public int $limiar = 65;

    public function boot(): void
    {
        abort_unless(Gate::allows('admin'), 403);
    }

    public function mount(): void
    {
        $this->validarLimiar();
    }

    public function updated(string $propriedade): void
    {
        if (in_array($propriedade, ['agente', 'categoria', 'busca'], true)) {
            $this->memoria = 0;
            $this->pagina = 1;
        }

        if ($propriedade === 'limiar') {
            $this->validarLimiar();
        }
    }

    public function irParaPagina(int $pagina): void
    {
        abort_unless(Gate::allows('admin'), 403);
        $this->pagina = max(1, $pagina);
        $this->memoria = 0;
    }

    /** Abre uma memória no painel e centraliza as ramificações nela. */
    public function selecionar(int $id): void
    {
        abort_unless(Gate::allows('admin'), 403);
        $this->memoria = max(0, $id);
    }

    private function validarLimiar(): void
    {
        if (! in_array($this->limiar, IndiceConhecimento::LIMIARES, true)) {
            $this->limiar = IndiceConhecimento::LIMIARES[0];
        }
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

    /** @return array<string, mixed>|null null quando o índice não respondeu */
    #[Computed]
    public function mapa(): ?array
    {
        abort_unless(Gate::allows('admin'), 403);
        try {
            return app(IndiceConhecimento::class)->mapa($this->agente, $this->categoria, $this->busca, $this->limiar);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{id: int, conteudo: string, categoria: string, agente: string, arquivo: string, criado: string}|null */
    #[Computed]
    public function selecionada(): ?array
    {
        if ($this->memoria <= 0) {
            return null;
        }
        try {
            return app(IndiceConhecimento::class)->memoria($this->memoria);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return list<array{id: int, nivel: int, pai: ?int, similaridade: ?float, x: float, y: float}> */
    #[Computed]
    public function ramificacoes(): array
    {
        $mapa = $this->mapa();
        if ($this->memoria <= 0 || $mapa === null) {
            return [];
        }

        return app(MapaConhecimento::class)->ramificacoes($mapa['vizinhos'], $this->memoria);
    }

    public function render(): View
    {
        abort_unless(Gate::allows('admin'), 403);

        return view('livewire.conhecimento', [
            'dados' => $this->dados(),
            'categorias' => IndiceConhecimento::CATEGORIAS,
            'limiares' => IndiceConhecimento::LIMIARES,
        ]);
    }
}
