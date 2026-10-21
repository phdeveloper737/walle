<?php

namespace App\Livewire\Sactus;

use App\Jobs\RodarCicloSactus;
use App\Livewire\Concerns\ConfirmaSenha;
use App\Models\Auditoria;
use App\Models\CicloSactus;
use App\Models\Usuario;
use App\Sactus\RoboSactus;
use App\Sactus\RotinaErp;
use App\Sactus\ServidoresSactus;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Aba Robô do Sactus: estado, o que ele vai fazer (lido do Help Desk), controles e os últimos ciclos.
 * Os controles são de admin com senha: rodar um ciclo mexe em ~250 servidores de clientes.
 *
 * @property-read CicloSactus|null $cicloAtual
 * @property-read Collection<int, CicloSactus> $ultimosCiclos
 * @property-read array<string, int>|null $plano
 */
class Robo extends Component
{
    use ConfirmaSenha;

    public const ACOES_COM_SENHA = ['pausar', 'retomar', 'rodarAgora'];

    public const PAUSAS_HORAS = [1, 4, 24];

    public ?string $aviso = null;

    public bool $avisoErro = false;

    // Sem injeção nos parâmetros: depois da senha o trait chama o método direto, só com os argumentos guardados

    public function pausar(int $horas): void
    {
        if (! in_array($horas, self::PAUSAS_HORAS, true) || ! $this->adminConfirmado('pausar', [$horas])) {
            return;
        }

        app(RoboSactus::class)->pausar(now()->addHours($horas), 'Pausado por '.Usuario::nomeDoLogado().' pelo painel');
        Auditoria::registrar('sactus.robo.pausar', null, ['horas' => $horas]);
        $this->avisar("Robô pausado por {$horas} h.");
    }

    public function retomar(): void
    {
        if (! $this->adminConfirmado('retomar')) {
            return;
        }

        app(RoboSactus::class)->retomar();
        Auditoria::registrar('sactus.robo.retomar');
        $this->avisar('Robô retomado.');
    }

    public function rodarAgora(): void
    {
        $robo = app(RoboSactus::class);

        if (! $robo->ligado()) {
            $this->avisar('O Sactus do walle está desligado (WALLE_SACTUS_ATIVO=false).', erro: true);

            return;
        }

        if (! $this->adminConfirmado('rodarAgora')) {
            return;
        }

        if ($robo->pausa() !== null || $this->cicloAtual !== null) {
            $this->avisar($robo->pausa() !== null ? 'O robô está pausado: retome antes.' : 'Já tem um ciclo em andamento.', erro: true);

            return;
        }

        RodarCicloSactus::dispatch();
        Auditoria::registrar('sactus.robo.rodar_agora');
        $this->avisar('Ciclo pedido: começa em instantes.');
    }

    #[Computed]
    public function cicloAtual(): ?CicloSactus
    {
        return CicloSactus::query()->whereNull('terminado_em')
            ->where('iniciado_em', '>=', now()->subMinutes((int) config('walle.sactus.limite_ciclo_minutos') + 10))
            ->latest('iniciado_em')->first();
    }

    /** @return Collection<int, CicloSactus> */
    #[Computed]
    public function ultimosCiclos(): Collection
    {
        return CicloSactus::query()->latest('iniciado_em')->limit(15)->get();
    }

    /**
     * O que o robô faria agora, pelo cadastro do Help Desk (null = Help fora do ar). Guardado por 5 min.
     *
     * @return array<string, int>|null
     */
    #[Computed]
    public function plano(): ?array
    {
        try {
            return Cache::remember('sactus:plano', 300, fn (): array => app(ServidoresSactus::class)->resumo(app(RotinaErp::class)->configurado()));
        } catch (QueryException $e) {
            report($e);

            return null;
        }
    }

    public function render(RoboSactus $robo, RotinaErp $erp): View
    {
        return view('livewire.sactus.robo', [
            'ligado' => $robo->ligado(),
            'pausa' => $robo->pausa(),
            'erpConfigurado' => $erp->configurado(),
            'admin' => Gate::allows('admin'),
        ]);
    }

    private function avisar(string $texto, bool $erro = false): void
    {
        $this->aviso = $texto;
        $this->avisoErro = $erro;
        unset($this->cicloAtual, $this->ultimosCiclos);
    }
}
