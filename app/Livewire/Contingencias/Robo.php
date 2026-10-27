<?php

namespace App\Livewire\Contingencias;

use App\Models\PainelContingencyCrawler;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Painel do Contingência BOT: totais e última coleta de cada cliente (substitui o contingency.dash.php).
 * Só leitura; o erro exibido é o da coleta, que já sai sanitizado do robô.
 *
 * @property-read Collection<int, PainelContingencyCrawler> $linhas
 * @property-read array<int, string>|null $nomes null = Help Desk fora do ar
 * @property-read array{clientes: int, com: int, sem: int, total: int, falhas: int} $resumo
 */
class Robo extends Component
{
    /** @return Collection<int, PainelContingencyCrawler> */
    #[Computed]
    public function linhas(): Collection
    {
        return PainelContingencyCrawler::query()->with('ultimaColeta')
            ->orderByDesc('total_cliente')->orderBy('fk_c_cliente')->get();
    }

    /** @return array<int, string>|null */
    #[Computed]
    public function nomes(): ?array
    {
        try {
            return DB::connection('help')->table('c_cliente')
                ->whereIn('id', $this->linhas->pluck('fk_c_cliente')->all())
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
        $com = $this->linhas->filter(fn (PainelContingencyCrawler $l): bool => (int) $l->total_cliente > 0);

        return [
            'clientes' => $this->linhas->count(),
            'com' => $com->count(),
            'sem' => $this->linhas->count() - $com->count(),
            'total' => (int) $com->sum('total_cliente'),
            'falhas' => $this->linhas->filter(fn (PainelContingencyCrawler $l): bool => $l->ultimaColeta?->status === 'falha')->count(),
        ];
    }

    public function render(): View
    {
        return view('livewire.contingencias.robo', ['ligado' => (bool) config('walle.contingencias.ativo')]);
    }
}
