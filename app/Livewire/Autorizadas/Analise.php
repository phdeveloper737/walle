<?php

namespace App\Livewire\Autorizadas;

use App\Autorizadas\AnaliseAutorizadas;
use App\Autorizadas\Periodo;
use App\Autorizadas\RoboAutorizadas;
use App\Contingencias\ListaDeClientes;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Aba Análise (substitui o autorizadas.analise.php): tendência da base inteira no topo
 * e, abaixo do filtro de período, tudo recortado pelo mesmo período.
 *
 * @property-read Periodo $recorte
 * @property-read array<int, string> $nomesClientes
 */
#[Lazy]
class Analise extends Component
{
    #[Url(except: 'mes')]
    public string $periodo = 'mes';

    /** Cliente escolhido no ranking: filtra a lista das pendentes mais antigas. */
    #[Url(as: 'cliente')]
    public ?int $clienteId = null;

    public function updatedPeriodo(): void
    {
        if (! array_key_exists($this->periodo, Periodo::ATALHOS)) {
            $this->periodo = 'mes';
        }
    }

    public function filtrarCliente(int $id): void
    {
        $this->clienteId = $this->clienteId === $id ? null : $id;
    }

    #[Computed]
    public function recorte(): Periodo
    {
        return Periodo::doAtalho($this->periodo, app(RoboAutorizadas::class)->corteRetencao());
    }

    /**
     * id => razão social, do Help Desk (sem ele, a tela mostra só o #id).
     *
     * @return array<int, string>
     */
    #[Computed]
    public function nomesClientes(): array
    {
        return app(ListaDeClientes::class)->nomes();
    }

    public function nomeCliente(int $id): string
    {
        return $id === 0 ? 'Cliente não identificado' : ($this->nomesClientes[$id] ?? "Cliente #{$id}");
    }

    public function placeholder(): View
    {
        return view('livewire.autorizadas.analise-carregando');
    }

    public function render(AnaliseAutorizadas $analise): View
    {
        return view('livewire.autorizadas.analise', [
            'semanas' => $analise->semanas(),
            'diario' => $analise->diario(),
            'resumo' => $analise->periodo($this->recorte),
            'clientes' => $analise->clientes($this->recorte),
            'perfil' => $analise->perfil($this->recorte),
            'latencia' => $analise->latencia($this->recorte),
            'pendentes' => $analise->pendentesAntigas($this->clienteId),
        ]);
    }
}
