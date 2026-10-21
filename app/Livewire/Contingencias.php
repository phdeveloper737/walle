<?php

namespace App\Livewire;

use App\Contingencias\ClienteDaLista;
use App\Contingencias\ListaDeClientes;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Contingências: lista de clientes (Help Desk) à esquerda e o cliente escolhido à direita.
 * Substitui system/consultorias/contingencias do sistema antigo.
 */
#[Title('Contingências')]
class Contingencias extends Component
{
    public const FILTROS = ['todos', 'consultorias', 'outros'];

    #[Url(except: '')]
    public string $busca = '';

    #[Url(except: 'todos')]
    public string $filtro = 'todos';

    #[Url(as: 'cliente')]
    public ?int $clienteId = null;

    public function updatedFiltro(): void
    {
        if (! in_array($this->filtro, self::FILTROS, true)) {
            $this->filtro = 'todos';
        }
    }

    public function selecionar(int $id): void
    {
        $this->clienteId = $id;
    }

    public function recarregar(ListaDeClientes $lista): void
    {
        $lista->esquecer();
        unset($this->todosClientes);
    }

    /** @return list<ClienteDaLista>|null null = Help Desk fora do ar */
    #[Computed]
    public function todosClientes(): ?array
    {
        try {
            return app(ListaDeClientes::class)->todos();
        } catch (QueryException $e) {
            report($e);

            return null;
        }
    }

    /** @return list<ClienteDaLista> */
    #[Computed]
    public function clientes(): array
    {
        return array_values(array_filter(
            $this->todosClientes ?? [],
            fn (ClienteDaLista $cliente): bool => match ($this->filtro) {
                'consultorias' => $cliente->consultoria,
                'outros' => ! $cliente->consultoria,
                default => true,
            } && $cliente->corresponde($this->busca),
        ));
    }

    /** @return array<string, string> */
    #[Computed]
    public function opcoesFiltro(): array
    {
        $todos = $this->todosClientes ?? [];
        $consultorias = count(array_filter($todos, fn (ClienteDaLista $c): bool => $c->consultoria));

        return [
            'todos' => 'Todos ('.count($todos).')',
            'consultorias' => 'Consultorias ('.$consultorias.')',
            'outros' => 'Outros ('.(count($todos) - $consultorias).')',
        ];
    }

    #[Computed]
    public function clienteSelecionado(): ?ClienteDaLista
    {
        foreach ($this->todosClientes ?? [] as $cliente) {
            if ($cliente->id === $this->clienteId) {
                return $cliente;
            }
        }

        return null;
    }

    public function render(): View
    {
        return view('livewire.contingencias');
    }
}
