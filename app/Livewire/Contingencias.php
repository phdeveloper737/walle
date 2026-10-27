<?php

namespace App\Livewire;

use App\Contingencias\ClienteDaLista;
use App\Contingencias\ListaDeClientes;
use App\Contingencias\RemocaoDeCliente;
use App\Livewire\Concerns\ConfirmaSenha;
use App\Models\Auditoria;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;
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
    use ConfirmaSenha;

    public const ACOES_COM_SENHA = ['removerCliente'];

    public const FILTROS = ['todos', 'consultorias', 'outros'];

    public const ABAS = [
        'clientes' => ['rotulo' => 'Clientes', 'icone' => 'groups'],
        'robo' => ['rotulo' => 'Robô', 'icone' => 'smart_toy'],
    ];

    #[Url(except: 'clientes')]
    public string $aba = 'clientes';

    #[Url(except: '')]
    public string $busca = '';

    #[Url(except: 'todos')]
    public string $filtro = 'todos';

    #[Url(as: 'cliente')]
    public ?int $clienteId = null;

    public ?string $aviso = null;

    public bool $avisoErro = false;

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

    /** Apaga as vendas do Versatus em contingência do cliente (delete.php do 220), só admin com a senha confirmada. */
    public function removerCliente(int $clienteId): void
    {
        if (! $this->adminConfirmado('removerCliente', [$clienteId])) {
            return;
        }

        abort_unless(Gate::allows('admin'), 403);

        $apagadas = app(RemocaoDeCliente::class)->remover($clienteId);

        Auditoria::registrar('contingencias.remover_cliente', $clienteId > 0 ? $clienteId : null, ['apagadas' => $apagadas]);

        $this->avisoErro = $apagadas === 0;
        $this->aviso = match (true) {
            $clienteId <= 0 => 'Cliente inválido: nada foi removido.',
            $apagadas === 0 => "Não há contingências gravadas do cliente #{$clienteId}: nada foi removido.",
            default => "{$apagadas} ".($apagadas === 1 ? 'registro' : 'registros')." de contingência do cliente #{$clienteId} ".($apagadas === 1 ? 'foi removido' : 'foram removidos').'.',
        };
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
