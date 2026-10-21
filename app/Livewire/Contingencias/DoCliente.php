<?php

namespace App\Livewire\Contingencias;

use App\Contingencias\Autorizada;
use App\Contingencias\BuscaContingencias;
use App\Contingencias\Cliente\FalhaConexaoCliente;
use App\Contingencias\Contingencia;
use App\Contingencias\ResultadoContingencias;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Lazy;
use Livewire\Component;

/**
 * Contingências do cliente escolhido: pendentes (com os itens e as inconsistências fiscais) e já autorizadas.
 * Carrega depois da página (lazy): a leitura no servidor do cliente pode levar alguns segundos.
 *
 * @property-read array{resultado: ResultadoContingencias|null, erro: string|null} $busca
 * @property-read list<Contingencia> $pendentes
 * @property-read list<Autorizada> $autorizadas
 */
#[Lazy]
class DoCliente extends Component
{
    public const POR_PAGINA = 20;

    public const ABAS = ['pendentes', 'autorizadas'];

    public int $clienteId;

    public string $aba = 'pendentes';

    public string $filtro = '';

    public int $pagina = 1;

    public function updatedAba(): void
    {
        if (! in_array($this->aba, self::ABAS, true)) {
            $this->aba = 'pendentes';
        }

        $this->pagina = 1;
    }

    public function updatedFiltro(): void
    {
        $this->pagina = 1;
    }

    public function irParaPagina(int $pagina): void
    {
        $this->pagina = max(1, $pagina);
    }

    public function recarregar(BuscaContingencias $busca): void
    {
        $busca->esquecer($this->clienteId);
        unset($this->busca);
        $this->pagina = 1;
    }

    /** @return array{resultado: ResultadoContingencias|null, erro: string|null} */
    #[Computed]
    public function busca(): array
    {
        try {
            return ['resultado' => app(BuscaContingencias::class)->buscar($this->clienteId), 'erro' => null];
        } catch (FalhaConexaoCliente $falha) {
            report($falha);

            return ['resultado' => null, 'erro' => $falha->getMessage()];
        } catch (QueryException $erro) {
            report($erro);

            return ['resultado' => null, 'erro' => 'Não foi possível consultar o Help Desk agora. Tente de novo em instantes.'];
        }
    }

    /** @return list<Contingencia> */
    #[Computed]
    public function pendentes(): array
    {
        return array_values(array_filter(
            $this->busca['resultado']->pendentes ?? [],
            fn (Contingencia $contingencia): bool => $contingencia->corresponde($this->filtro),
        ));
    }

    /** @return list<Autorizada> */
    #[Computed]
    public function autorizadas(): array
    {
        return array_values(array_filter(
            $this->busca['resultado']->autorizadas ?? [],
            fn (Autorizada $autorizada): bool => $autorizada->contingencia->corresponde($this->filtro) || str_contains($autorizada->chave, trim($this->filtro)),
        ));
    }

    public function placeholder(): View
    {
        return view('livewire.contingencias.do-cliente-carregando');
    }

    public function render(): View
    {
        $lista = $this->aba === 'autorizadas' ? $this->autorizadas : $this->pendentes;
        $totalPaginas = max(1, (int) ceil(count($lista) / self::POR_PAGINA));
        $this->pagina = min($this->pagina, $totalPaginas);

        return view('livewire.contingencias.do-cliente', [
            'itensDaPagina' => array_slice($lista, ($this->pagina - 1) * self::POR_PAGINA, self::POR_PAGINA),
            'totalNaLista' => count($lista),
            'totalPaginas' => $totalPaginas,
        ]);
    }
}
