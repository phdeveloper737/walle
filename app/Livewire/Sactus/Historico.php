<?php

namespace App\Livewire\Sactus;

use App\Contingencias\ListaDeClientes;
use App\Models\AcaoSactus;
use App\Models\SactusCfgEdicao;
use App\Sactus\ArquivoCfg;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Pedidos de edição do sactus.cfg e ações manuais de todos os clientes (os mais recentes primeiro).
 *
 * @property-read Collection<int, SactusCfgEdicao> $pedidos
 * @property-read Collection<int, AcaoSactus> $acoes
 */
class Historico extends Component
{
    public const STATUS = ['PENDENTE' => 'Pendentes', 'PROCESSANDO' => 'Processando', 'CONCLUIDO' => 'Concluídos', 'ERRO' => 'Com erro'];

    #[Url(except: '')]
    public string $status = '';

    /** @return Collection<int, SactusCfgEdicao> */
    #[Computed]
    public function pedidos(): Collection
    {
        return SactusCfgEdicao::query()
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->latest('id')->limit(100)->get();
    }

    /** @return Collection<int, AcaoSactus> */
    #[Computed]
    public function acoes(): Collection
    {
        return AcaoSactus::query()
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->latest('id')->limit(50)->get();
    }

    public function render(ListaDeClientes $lista): View
    {
        return view('livewire.sactus.historico', [
            'nomes' => $lista->nomes(),
            'sensivel' => ArquivoCfg::sensivel(...),
        ]);
    }
}
