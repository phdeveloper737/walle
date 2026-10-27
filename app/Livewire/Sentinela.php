<?php

namespace App\Livewire;

use App\Models\PainelSentinela;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Painel de saúde dos servidores dos clientes, coletada pelo robô do Sentinela (substitui o sentinela.dash.php).
 * Só leitura; o erro exibido é o da coleta, que já sai sem segredos do robô.
 *
 * @property-read Collection<int, PainelSentinela> $linhas
 * @property-read array<int, string> $nomes
 * @property-read array{total: int, online: int, offline: int, obsoletos: int, alertas: int} $resumo
 */
#[Title('Sentinela')]
class Sentinela extends Component
{
    /** Mais de 30 minutos sem atualizar = coleta obsoleta (igual ao sistema antigo). */
    public const OBSOLETO_MINUTOS = 30;

    public const DISCO_CRITICO = 90;

    public const MEMORIA_CRITICA = 90;

    /** Filtro pelo cliente (o ?id= do sistema antigo). */
    #[Url]
    public ?int $cliente = null;

    /** @return Collection<int, PainelSentinela> */
    #[Computed]
    public function linhas(): Collection
    {
        return PainelSentinela::query()
            ->when($this->cliente !== null, fn ($q) => $q->where('fk_c_cliente', $this->cliente))
            ->orderBy('status')->orderBy('fk_c_cliente')
            ->get();
    }

    /** @return array<int, string> nomes vindos do banco do walle (informacao_tecnica_painel_contingencias) */
    #[Computed]
    public function nomes(): array
    {
        return DB::table('informacao_tecnica_painel_contingencias')
            ->whereIn('fk_c_cliente', $this->linhas->pluck('fk_c_cliente')->all())
            ->get(['fk_c_cliente', 'razao_social', 'nome_fantasia'])
            ->mapWithKeys(fn (object $c): array => [
                (int) $c->fk_c_cliente => trim((string) $c->razao_social) ?: trim((string) $c->nome_fantasia),
            ])->filter()->all();
    }

    /** @return array{total: int, online: int, offline: int, obsoletos: int, alertas: int} */
    #[Computed]
    public function resumo(): array
    {
        $online = $this->linhas->where('status', 'S');

        return [
            'total' => $this->linhas->count(),
            'online' => $online->count(),
            'offline' => $this->linhas->count() - $online->count(),
            'obsoletos' => $this->linhas->filter(fn (PainelSentinela $l): bool => $this->obsoleta($l))->count(),
            'alertas' => $online->filter(fn (PainelSentinela $l): bool => $this->alertas($l) !== [])->count(),
        ];
    }

    public function obsoleta(PainelSentinela $linha): bool
    {
        return $linha->data->lt(now()->subMinutes(self::OBSOLETO_MINUTOS));
    }

    /** @return list<string> problemas de um servidor que responde */
    public function alertas(PainelSentinela $linha): array
    {
        $alertas = [];
        if ($linha->sactus_rodando === 'N') {
            $alertas[] = 'Sactus parado';
        }
        if ($linha->disco_pct >= self::DISCO_CRITICO) {
            $alertas[] = 'Disco em '.$linha->disco_pct.'%';
        }
        if ((float) $linha->mem_pct >= self::MEMORIA_CRITICA) {
            $alertas[] = 'Memória em '.round((float) $linha->mem_pct).'%';
        }
        if ($linha->sactus_md5_ok === 'N') {
            $alertas[] = 'Binário do Sactus diferente';
        }
        if ($linha->vpn_inconsistente === 'S') {
            $alertas[] = 'MTU da VPN inconsistente';
        }

        return $alertas;
    }

    public function render(): View
    {
        return view('livewire.sentinela', ['ligado' => (bool) config('walle.sentinela.ativo')]);
    }
}
