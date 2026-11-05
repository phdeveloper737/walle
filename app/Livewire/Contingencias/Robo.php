<?php

namespace App\Livewire\Contingencias;

use App\Models\ColetaContingencias;
use App\Models\PainelContingencyCrawler;
use App\Support\ConfiguracaoRobo;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Painel do Contingência BOT: totais e última coleta de cada cliente (substitui o contingency.dash.php).
 * Só leitura; o erro exibido é o da coleta, que já sai sanitizado do robô.
 *
 * @property-read SupportCollection<int, array{cliente: int, painel: PainelContingencyCrawler|null, coleta: ColetaContingencias|null, total: int|null}> $linhas
 * @property-read Collection<int, ColetaContingencias> $coletasDoCliente
 * @property-read string $tituloColetas
 * @property-read array<int, string>|null $nomes null = Help Desk fora do ar
 * @property-read array{clientes: int, com: int, sem: int, total: int, falhas: int} $resumo
 */
class Robo extends Component
{
    public const COLETAS_NA_JANELA = 20;

    /** Cliente cujas coletas estão na janela; null = janela fechada. */
    #[Locked]
    public ?int $clienteColetas = null;

    /**
     * Um item por cliente que tem linha no painel ou alguma coleta: com falha primeiro, depois por total.
     *
     * @return SupportCollection<int, array{cliente: int, painel: PainelContingencyCrawler|null, coleta: ColetaContingencias|null, total: int|null}>
     */
    #[Computed]
    public function linhas(): SupportCollection
    {
        $painel = PainelContingencyCrawler::query()->get()->keyBy('fk_c_cliente');
        $coletas = ColetaContingencias::query()
            ->whereIn('id', ColetaContingencias::query()->selectRaw('max(id)')->groupBy('fk_c_cliente'))
            ->get()->keyBy('fk_c_cliente');

        return $painel->keys()->merge($coletas->keys())->unique()
            ->map(fn ($id): array => [
                'cliente' => (int) $id,
                'painel' => $painel->get($id),
                'coleta' => $coletas->get($id),
                'total' => $painel->get($id)?->total_cliente,
            ])
            ->sort(fn (array $a, array $b): int => [
                ($b['coleta']?->status === 'falha') <=> ($a['coleta']?->status === 'falha'),
                ($b['total'] ?? -1) <=> ($a['total'] ?? -1),
                $a['cliente'] <=> $b['cliente'],
            ] <=> [0, 0, 0])
            ->values();
    }

    /** @return Collection<int, ColetaContingencias> */
    #[Computed]
    public function coletasDoCliente(): Collection
    {
        if ($this->clienteColetas === null) {
            return new Collection;
        }

        $colunas = ['id', 'ciclo_id', 'fk_c_cliente', 'iniciado_em', 'terminado_em', 'status', 'erro'];

        if (Gate::allows('admin')) {
            $colunas[] = 'detalhe_tecnico';
        }

        // O detalhe técnico só sai do banco para admin: quem não é admin nunca o recebe no servidor.
        return ColetaContingencias::query()->select($colunas)->where('fk_c_cliente', $this->clienteColetas)
            ->orderByDesc('iniciado_em')->orderByDesc('id')->limit(self::COLETAS_NA_JANELA)->get();
    }

    #[Computed]
    public function tituloColetas(): string
    {
        if ($this->clienteColetas === null) {
            return 'Coletas';
        }

        return 'Coletas de '.($this->nomes[$this->clienteColetas] ?? 'Cliente #'.$this->clienteColetas);
    }

    public function verColetas(int $clienteId): void
    {
        if ($clienteId < 1) {
            return;
        }

        $this->clienteColetas = $clienteId;
    }

    public function fecharColetas(): void
    {
        $this->clienteColetas = null;
    }

    /** @return array<int, string>|null */
    #[Computed]
    public function nomes(): ?array
    {
        try {
            return DB::connection('help')->table('c_cliente')
                ->whereIn('id', $this->linhas->pluck('cliente')->merge($this->clienteColetas ?? [])->all())
                ->pluck('razao_social', 'id')->map(fn ($nome): string => trim((string) $nome))->all();
        } catch (QueryException $e) {
            report($e);

            return null;
        }
    }

    /** @return array{clientes: int, com: int, sem: int, total: int, falhas: int} */
    #[Computed]
    public function resumo(): array
    {
        $com = $this->linhas->filter(fn (array $l): bool => (int) $l['total'] > 0);

        return [
            'clientes' => $this->linhas->count(),
            'com' => $com->count(),
            'sem' => $this->linhas->count() - $com->count(),
            'total' => (int) $com->sum('total'),
            'falhas' => $this->linhas->filter(fn (array $l): bool => $l['coleta']?->status === 'falha')->count(),
        ];
    }

    public function render(): View
    {
        return view('livewire.contingencias.robo', [
            'admin' => Gate::allows('admin'),
            'ligado' => ConfiguracaoRobo::ligado('contingencias'),
            'permissaoEnv' => ConfiguracaoRobo::permissaoEnv('contingencias'),
            'ativoLocal' => ConfiguracaoRobo::ativoLocal('contingencias'),
        ]);
    }
}
