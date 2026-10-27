<?php

namespace App\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Relatórios: clientes (Help Desk, só leitura), histórico de contingências NFC-e de um cliente e ranking de motivos.
 * Substitui system/consultorias/relatorios do sistema antigo.
 *
 * @property-read list<object>|null $clientes null = Help Desk fora do ar
 * @property-read list<object> $historico
 * @property-read list<array{motivo: string, total: int}> $motivos
 */
#[Title('Relatórios')]
class Relatorios extends Component
{
    public const ABAS = [
        'clientes' => ['rotulo' => 'Clientes', 'icone' => 'groups'],
        'contingencia' => ['rotulo' => 'Contingência NFC-e', 'icone' => 'receipt_long'],
        'motivos' => ['rotulo' => 'Motivos', 'icone' => 'bar_chart'],
    ];

    /** Máximo de linhas do histórico por cliente (igual ao sistema antigo). */
    public const LIMITE_HISTORICO = 1000;

    #[Url(except: 'clientes')]
    public string $aba = 'clientes';

    /** Aba Clientes: S = só consultoria, N = só os demais. */
    #[Url(except: 'S')]
    public string $consultoria = 'S';

    /** Aba Contingência NFC-e: id do cliente no Help Desk. */
    #[Url]
    public ?int $cliente = null;

    /** Aba Motivos: período (padrão: a semana atual, de segunda a domingo). */
    #[Url(except: '')]
    public string $de = '';

    #[Url(except: '')]
    public string $ate = '';

    /** Aba Motivos: N = kits automáticos não, S = kits automáticos sim, vazio = todos. */
    #[Url(except: 'N')]
    public string $tipo = 'N';

    public function mount(): void
    {
        $this->updatedAba();
        $this->updatedConsultoria();
        $this->updatedTipo();

        if ($this->de === '' && $this->ate === '') {
            $this->de = now()->startOfWeek()->toDateString();
            $this->ate = now()->endOfWeek()->toDateString();
        }
    }

    public function updatedAba(): void
    {
        if (! array_key_exists($this->aba, self::ABAS)) {
            $this->aba = 'clientes';
        }
    }

    public function updatedConsultoria(): void
    {
        if (! in_array($this->consultoria, ['S', 'N'], true)) {
            $this->consultoria = 'S';
        }
    }

    public function updatedTipo(): void
    {
        if (! in_array($this->tipo, ['N', 'S', ''], true)) {
            $this->tipo = 'N';
        }
    }

    /** @return list<object>|null null = Help Desk fora do ar */
    #[Computed]
    public function clientes(): ?array
    {
        try {
            return DB::connection('help')->table('c_cliente')
                ->select(['id', 'loja', 'razao_social', 'nome_fantasia', 'tipo', 'cnpj', 'cpf', 'contato', 'telefone', 'celular', 'email', 'deletado'])
                ->where('consultoria', $this->consultoria)
                ->where('deletado', 'N')
                ->orderBy('nome_fantasia')
                ->orderBy('razao_social')
                ->get()
                ->all();
        } catch (QueryException $e) {
            report($e);

            return null;
        }
    }

    /** @return list<object> */
    #[Computed]
    public function historico(): array
    {
        if ($this->cliente === null || $this->cliente <= 0) {
            return [];
        }

        return DB::table('history_contingencia')
            ->where('fk_c_cliente', $this->cliente)
            ->orderByDesc('data_fiscal_xml')
            ->limit(self::LIMITE_HISTORICO)
            ->get(['serie', 'numero', 'chave', 'motivo', 'software', 'data_fiscal_xml'])
            ->all();
    }

    /**
     * Ranking de motivos do período. Mesma regra do get.motivos.contingencias.php do 220: motivo aparado e em
     * maiúsculas, cortado em "(", "[" e "NITEM", e "SEM COMUNICACAO COM ..." unificado; ordenado do maior para o menor.
     *
     * @return list<array{motivo: string, total: int}>
     */
    #[Computed]
    public function motivos(): array
    {
        $consulta = DB::table('history_contingencia as h')
            ->join('informacao_tecnica_painel_contingencias as c', 'c.id', '=', 'h.fk_c_cliente')
            ->whereNotNull('h.motivo');

        if ($this->tipo !== '') {
            $consulta->where('c.emissao_cupons', $this->tipo);
        }

        if ($de = $this->dataValida($this->de)) {
            $consulta->where('h.data_clique', '>=', $de->startOfDay());
        }

        if ($ate = $this->dataValida($this->ate)) {
            $consulta->where('h.data_clique', '<=', $ate->endOfDay());
        }

        $contagem = [];
        foreach ($consulta->pluck('h.motivo') as $bruto) {
            $motivo = self::normalizarMotivo((string) $bruto);

            if ($motivo !== '') {
                $contagem[$motivo] = ($contagem[$motivo] ?? 0) + 1;
            }
        }

        arsort($contagem);

        $ranking = [];
        foreach ($contagem as $motivo => $total) {
            $ranking[] = ['motivo' => (string) $motivo, 'total' => $total];
        }

        return $ranking;
    }

    public static function normalizarMotivo(string $motivo): string
    {
        $motivo = strtoupper(trim($motivo));

        foreach (['(', '[', 'NITEM'] as $corte) {
            if (str_contains($motivo, $corte)) {
                $motivo = trim(explode($corte, $motivo)[0]);
            }
        }

        if (str_starts_with($motivo, 'SEM COMUNICACAO COM')) {
            return 'SEM COMUNICACAO COM A SEFAZ';
        }

        return $motivo;
    }

    private function dataValida(string $data): ?Carbon
    {
        try {
            $valida = Carbon::createFromFormat('!Y-m-d', $data);
        } catch (\Throwable) {
            return null;
        }

        return $valida->toDateString() === $data ? $valida : null;
    }

    public function render(): View
    {
        return view('livewire.relatorios');
    }
}
