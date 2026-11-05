<?php

namespace App\Livewire\Robos;

use App\Models\RoboConfigHistorico;
use App\Support\ParametroCatalogo;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Aba Histórico de um robô: edições, ativações e desativações da configuração (tabela append-only do walle).
 * Só leitura; os valores já saem sanitizados do serviço de configuração.
 *
 * @property-read LengthAwarePaginator<int, RoboConfigHistorico>|null $historico null = banco do walle com erro
 */
class Historico extends Component
{
    public const POR_PAGINA = 15;

    /** Dias contados com hoje incluso; 'todos' não recorta. */
    public const PERIODOS = ['1' => 'Hoje', '7' => '7 dias', '30' => '30 dias', '90' => '90 dias', '365' => '1 ano', 'todos' => 'Tudo'];

    public const ACOES = ['alterar' => 'Alterado', 'ativar' => 'Ligado', 'desativar' => 'Desligado'];

    #[Locked]
    public string $robo = '';

    #[Url(except: '30')]
    public string $periodo = '30';

    #[Url(except: '')]
    public string $parametro = '';

    #[Url(except: '')]
    public string $acao = '';

    #[Url(except: '')]
    public string $ator = '';

    #[Url(except: 1)]
    public int $pagina = 1;

    public function mount(string $robo): void
    {
        abort_unless(isset(ParametroCatalogo::todos()[$robo]), 404);

        $this->robo = $robo;
        $this->normalizar();
    }

    public function updated(string $propriedade): void
    {
        $this->normalizar();

        if (in_array($propriedade, ['periodo', 'parametro', 'acao', 'ator'], true)) {
            $this->pagina = 1;
        }
    }

    public function irParaPagina(int $pagina): void
    {
        $this->pagina = max(1, $pagina);
    }

    /** @return LengthAwarePaginator<int, RoboConfigHistorico>|null */
    #[Computed]
    public function historico(): ?LengthAwarePaginator
    {
        try {
            return RoboConfigHistorico::query()
                ->where('robo', $this->robo)
                ->when($this->periodo !== 'todos', fn ($q) => $q->where('criado_em', '>=', now()->subDays((int) $this->periodo - 1)->startOfDay()))
                ->when($this->parametro !== '', fn ($q) => $q->where('parametro', 'like', '%'.addcslashes($this->parametro, '%_\\').'%'))
                ->when($this->acao !== '', fn ($q) => $q->where('acao', $this->acao))
                ->when($this->ator !== '', fn ($q) => $q->where('ator', 'like', '%'.addcslashes($this->ator, '%_\\').'%'))
                ->orderByDesc('id')
                ->paginate(self::POR_PAGINA, page: max(1, $this->pagina));
        } catch (QueryException $e) {
            report($e);

            return null;
        }
    }

    public function render(): View
    {
        return view('livewire.robos.historico');
    }

    /** Valor inválido da URL (período ou ação) volta ao padrão, sem erro. */
    private function normalizar(): void
    {
        if (! array_key_exists($this->periodo, self::PERIODOS)) {
            $this->periodo = '30';
        }

        if ($this->acao !== '' && ! array_key_exists($this->acao, self::ACOES)) {
            $this->acao = '';
        }

        $this->pagina = max(1, $this->pagina);
    }
}
