<?php

namespace App\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\Builder;
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
 * @property-read list<array{motivo: string, total: int}>|null $motivos null = Help Desk fora do ar
 * @property-read list<array{cliente: int, nome: string, cnpj: string, total: int}>|null $clientesDoMotivo null = Help Desk fora do ar
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

    /** Aba Motivos: motivo selecionado (normalizado); vazio = nada selecionado. */
    #[Url(except: '')]
    public string $motivo = '';

    public function mount(): void
    {
        $this->updatedAba();
        $this->validarConsultoria();
        $this->validarTipo();
        $this->motivo = self::normalizarMotivo($this->motivo);

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
        $this->validarConsultoria();
    }

    public function updatedTipo(): void
    {
        $this->validarTipo();
        $this->motivo = '';
    }

    public function updatedDe(): void
    {
        $this->motivo = '';
    }

    public function updatedAte(): void
    {
        $this->motivo = '';
    }

    private function validarConsultoria(): void
    {
        if (! in_array($this->consultoria, ['S', 'N'], true)) {
            $this->consultoria = 'S';
        }
    }

    private function validarTipo(): void
    {
        if (! in_array($this->tipo, ['N', 'S', ''], true)) {
            $this->tipo = 'N';
        }
    }

    /** @return list<int> ids de clientes elegíveis pelo tipo (dataserver LEFT JOIN cleantaxbot), ao vivo do Help Desk */
    private function clientesElegiveis(): array
    {
        $consultaClientes = DB::connection('help')->table('informacao_tecnica_dataserver as i')
            ->leftJoin('informacao_tecnica_cleantaxbot as ic', 'ic.fk_c_cliente', '=', 'i.fk_c_cliente')
            ->whereNotNull('i.fk_c_cliente');

        if ($this->tipo !== '') {
            $consultaClientes->where('ic.emissao_cupons', $this->tipo);
        }

        return $consultaClientes->distinct()->pluck('i.fk_c_cliente')->all();
    }

    /** @param list<int> $clienteIds */
    private function consultaHistorico(array $clienteIds): Builder
    {
        $consulta = DB::table('history_contingencia as h')
            ->whereIn('h.fk_c_cliente', $clienteIds)
            ->whereNotNull('h.motivo');

        if ($de = $this->dataValida($this->de)) {
            $consulta->where('h.data_clique', '>=', $de->startOfDay());
        }

        if ($ate = $this->dataValida($this->ate)) {
            $consulta->where('h.data_clique', '<=', $ate->endOfDay());
        }

        return $consulta;
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
     * Desvio do 220: lá os clientes elegíveis e o emissao_cupons vinham de informacao_tecnica_painel_contingencias,
     * um cache do Help Desk. O walle não copia tabelas do Help Desk, então os ids vêm ao vivo da conexão help
     * (informacao_tecnica_dataserver com informacao_tecnica_cleantaxbot.emissao_cupons, só leitura) e filtram o
     * history_contingencia com whereIn. Com tipo S ou N, cliente sem linha no cleantaxbot fica fora, como no 220.
     *
     * @return list<array{motivo: string, total: int}>|null null = Help Desk fora do ar
     */
    #[Computed]
    public function motivos(): ?array
    {
        try {
            $clienteIds = $this->clientesElegiveis();
            $motivosBrutos = $this->consultaHistorico($clienteIds)->pluck('h.motivo');
        } catch (QueryException $e) {
            report($e);

            return null;
        }

        $contagem = [];
        foreach ($motivosBrutos as $bruto) {
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

    /**
     * Clientes que tiveram o motivo selecionado, com o total por cliente.
     * Mesmo recorte do ranking (período, tipo e clientes elegíveis); casamento pelo motivo normalizado exato.
     *
     * @return list<array{cliente: int, nome: string, cnpj: string, total: int}>|null null = Help Desk fora do ar
     */
    #[Computed]
    public function clientesDoMotivo(): ?array
    {
        if ($this->motivo === '') {
            return [];
        }

        try {
            $clienteIds = $this->clientesElegiveis();

            $totais = [];
            foreach ($this->consultaHistorico($clienteIds)->get(['h.fk_c_cliente', 'h.motivo']) as $linha) {
                if (self::normalizarMotivo((string) $linha->motivo) !== $this->motivo) {
                    continue;
                }

                $id = (int) $linha->fk_c_cliente;
                $totais[$id] = ($totais[$id] ?? 0) + 1;
            }

            if ($totais === []) {
                return [];
            }

            $nomes = DB::connection('help')->table('c_cliente')
                ->whereIn('id', array_keys($totais))
                ->get(['id', 'razao_social', 'nome_fantasia', 'cnpj'])
                ->keyBy('id');
        } catch (QueryException $e) {
            report($e);

            return null;
        }

        $clientes = [];
        foreach ($totais as $id => $total) {
            $registro = $nomes->get($id);
            $razao = trim((string) ($registro->razao_social ?? ''));
            $fantasia = trim((string) ($registro->nome_fantasia ?? ''));

            $clientes[] = [
                'cliente' => $id,
                'nome' => $razao !== '' ? $razao : ($fantasia !== '' ? $fantasia : "Cliente #{$id}"),
                'cnpj' => (string) ($registro->cnpj ?? ''),
                'total' => $total,
            ];
        }

        usort($clientes, fn (array $a, array $b): int => [$b['total'], $a['cliente']] <=> [$a['total'], $b['cliente']]);

        return $clientes;
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

    /** Aba Motivos: seleciona o motivo do detalhe (normalizado) e abre a janela com os clientes. */
    public function selecionarMotivo(string $motivo): void
    {
        $this->motivo = self::normalizarMotivo($motivo);
    }

    public function limparMotivo(): void
    {
        $this->motivo = '';
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
