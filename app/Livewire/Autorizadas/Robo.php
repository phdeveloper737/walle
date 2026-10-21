<?php

namespace App\Livewire\Autorizadas;

use App\Autorizadas\AnaliseAutorizadas;
use App\Autorizadas\RoboAutorizadas;
use App\Jobs\RodarCicloAutorizadas;
use App\Models\CicloAutorizadas;
use App\Models\Usuario;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Aba Robô: estado (ligado, pausado, ciclo em andamento), números da base, consultas por hora
 * e os últimos ciclos. Substitui o autorizadas.dash.php (que mexia no systemctl e mostrava o log).
 *
 * @property-read array<string, mixed> $geral
 * @property-read CicloAutorizadas|null $cicloAtual
 * @property-read Collection<int, CicloAutorizadas> $ultimosCiclos
 * @property-read array{rotulos: list<string>, autorizadas: list<int>, outras: list<int>} $porHora
 */
class Robo extends Component
{
    /** Um ciclo leva minutos; sem terminar depois disso, o worker morreu no meio. */
    public const MINUTOS_CICLO_INTERROMPIDO = 35;

    public const PAUSAS_HORAS = [1, 4, 24];

    public ?string $aviso = null;

    public bool $avisoErro = false;

    public function pausar(int $horas, RoboAutorizadas $robo): void
    {
        if (! in_array($horas, self::PAUSAS_HORAS, true)) {
            return;
        }

        $nome = Usuario::nomeDoLogado();
        $robo->pausar(now()->addHours($horas), "Pausado por {$nome} pelo painel");
        Log::info('Robô das Autorizadas pausado pelo painel', ['usuario' => $nome, 'horas' => $horas]);

        $this->avisar("Robô pausado por {$horas} h.");
    }

    public function retomar(RoboAutorizadas $robo): void
    {
        $robo->retomar();
        Log::info('Robô das Autorizadas retomado pelo painel', ['usuario' => Usuario::nomeDoLogado()]);

        $this->avisar('Robô retomado.');
    }

    public function rodarAgora(RoboAutorizadas $robo): void
    {
        if (! $robo->ligado()) {
            $this->avisar('O robô está desligado neste servidor (WALLE_AUTORIZADAS_ATIVO=false).', erro: true);

            return;
        }

        if ($robo->pausadoAte() !== null) {
            $this->avisar('O robô está pausado: retome antes de rodar um ciclo.', erro: true);

            return;
        }

        if ($this->cicloAtual !== null) {
            $this->avisar('Já tem um ciclo em andamento.', erro: true);

            return;
        }

        // ShouldBeUnique: se o agendador já pôs um ciclo na fila, este não entra de novo
        RodarCicloAutorizadas::dispatch();
        $this->avisar('Ciclo pedido: começa em instantes.');
    }

    /**
     * Números da base (uma varredura da tabela inteira): guardados por 15 s, a tela atualiza a cada 10 s.
     * No cache só vai array: a "ultima" vira texto.
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function geral(): array
    {
        return Cache::remember('autorizadas:painel-geral', 15, function (): array {
            $g = app(AnaliseAutorizadas::class)->geral();

            return [...$g, 'ultima' => $g['ultima']?->toDateTimeString()];
        });
    }

    #[Computed]
    public function cicloAtual(): ?CicloAutorizadas
    {
        return CicloAutorizadas::query()
            ->whereNull('terminado_em')
            ->where('iniciado_em', '>=', now()->subMinutes(self::MINUTOS_CICLO_INTERROMPIDO))
            ->latest('iniciado_em')
            ->first();
    }

    /** @return Collection<int, CicloAutorizadas> */
    #[Computed]
    public function ultimosCiclos(): Collection
    {
        return CicloAutorizadas::query()->latest('iniciado_em')->limit(15)->get();
    }

    /**
     * Consultas das últimas 24 h, hora a hora, somadas dos ciclos.
     *
     * @return array{rotulos: list<string>, autorizadas: list<int>, outras: list<int>}
     */
    #[Computed]
    public function porHora(): array
    {
        $inicio = now()->subHours(23)->startOfHour();
        $linhas = DB::table('autorizadas_ciclos')
            ->where('iniciado_em', '>=', $inicio)
            ->selectRaw("DATE_FORMAT(iniciado_em, '%Y-%m-%d %H') AS hora, SUM(consultadas) AS consultadas, SUM(autorizadas) AS autorizadas, SUM(falhas) AS falhas")
            ->groupBy('hora')
            ->get()
            ->keyBy('hora');

        $serie = ['rotulos' => [], 'autorizadas' => [], 'outras' => []];

        for ($hora = $inicio->copy(); $hora->lte(now()); $hora->addHour()) {
            $l = $linhas->get($hora->format('Y-m-d H'));
            $serie['rotulos'][] = $hora->format('H\h');
            $serie['autorizadas'][] = (int) ($l->autorizadas ?? 0);
            // Consultadas que não voltaram autorizadas (as falhas ficam de fora: aparecem nos ciclos)
            $serie['outras'][] = max(0, (int) ($l->consultadas ?? 0) - (int) ($l->autorizadas ?? 0) - (int) ($l->falhas ?? 0));
        }

        return $serie;
    }

    public function render(RoboAutorizadas $robo): View
    {
        return view('livewire.autorizadas.robo', [
            'ligado' => $robo->ligado(),
            'pausa' => $robo->pausa(),
        ]);
    }

    private function avisar(string $texto, bool $erro = false): void
    {
        $this->aviso = $texto;
        $this->avisoErro = $erro;
        unset($this->cicloAtual, $this->ultimosCiclos);
    }
}
