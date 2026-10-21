<?php

namespace App\Livewire;

use App\Autorizadas\RoboAutorizadas;
use App\Models\CicloAutorizadas;
use App\Support\HelpDesk\SenhaHelpDesk;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

/** Confere, de verdade, cada peça do walle. A tela se atualiza sozinha (wire:poll). */
#[Title('Saúde do sistema')]
class Saude extends Component
{
    public function render(SenhaHelpDesk $senhaHelpDesk, MasterSupervisorRepository $supervisores, RoboAutorizadas $robo): View
    {
        return view('livewire.saude', [
            'itens' => [
                $this->aplicacao(),
                $this->conferir('Banco walle', 'database', function () {
                    $versao = DB::selectOne('select version() as versao')->versao;

                    return 'MySQL '.$versao.' · banco '.DB::getDatabaseName();
                }),
                $this->conferir('Valkey (cache, sessão e filas)', 'memory', function () {
                    $info = Redis::connection()->info('server');

                    return 'Valkey '.($info['valkey_version'] ?? $info['redis_version'] ?? '?');
                }),
                $this->conferir('Help Desk (192.168.10.254)', 'support_agent', function () use ($senhaHelpDesk) {
                    $versao = DB::connection('help')->selectOne('select version() as versao')->versao;
                    $origem = match ($senhaHelpDesk->origem()) {
                        'api' => 'senha pela API',
                        'reserva' => 'senha RESERVA (a API do Help falhou)',
                        default => 'senha em cache',
                    };

                    return 'MySQL '.$versao.' · '.$origem;
                }),
                $this->horizon($supervisores),
                $this->agendador(),
                $this->roboAutorizadas($robo),
            ],
            'conferidoEm' => now(),
        ]);
    }

    /** @return array{nome: string, icone: string, ok: bool, detalhe: string} */
    private function aplicacao(): array
    {
        return [
            'nome' => 'Aplicação',
            'icone' => 'code',
            'ok' => true,
            'detalhe' => 'PHP '.PHP_VERSION.' · Laravel '.app()->version(),
        ];
    }

    /**
     * Roda a conferência medindo o tempo; qualquer erro vira "fora do ar" com a mensagem.
     *
     * @param  callable(): string  $conferencia
     * @return array{nome: string, icone: string, ok: bool, detalhe: string}
     */
    private function conferir(string $nome, string $icone, callable $conferencia): array
    {
        $inicio = hrtime(true);

        try {
            $detalhe = $conferencia();
            $ms = (int) round((hrtime(true) - $inicio) / 1e6);

            return ['nome' => $nome, 'icone' => $icone, 'ok' => true, 'detalhe' => "{$detalhe} · {$ms} ms"];
        } catch (Throwable $e) {
            return ['nome' => $nome, 'icone' => $icone, 'ok' => false, 'detalhe' => $e->getMessage()];
        }
    }

    /** @return array{nome: string, icone: string, ok: bool, detalhe: string} */
    private function horizon(MasterSupervisorRepository $supervisores): array
    {
        try {
            $mestres = $supervisores->all();
        } catch (Throwable $e) {
            return ['nome' => 'Filas (Horizon)', 'icone' => 'stacks', 'ok' => false, 'detalhe' => $e->getMessage()];
        }

        if ($mestres === []) {
            return ['nome' => 'Filas (Horizon)', 'icone' => 'stacks', 'ok' => false, 'detalhe' => 'Parado — o contêiner walle-horizon está rodando?'];
        }

        $pausado = collect($mestres)->contains(fn ($mestre) => $mestre->status === 'paused');

        return [
            'nome' => 'Filas (Horizon)',
            'icone' => 'stacks',
            'ok' => ! $pausado,
            'detalhe' => $pausado ? 'Pausado' : 'Rodando',
        ];
    }

    /** @return array{nome: string, icone: string, ok: bool, detalhe: string} */
    private function agendador(): array
    {
        $timestamp = Cache::get('walle:agendador:batida');

        // O Valkey devolve número como texto ("1792..."), por isso is_numeric e não is_int
        if (! is_numeric($timestamp)) {
            return ['nome' => 'Agendador', 'icone' => 'schedule', 'ok' => false, 'detalhe' => 'Sem batida ainda — o contêiner walle-scheduler está rodando?'];
        }

        $batida = Carbon::createFromTimestamp((int) $timestamp, config('app.timezone'));
        $segundos = (int) $batida->diffInSeconds(now());

        return [
            'nome' => 'Agendador',
            'icone' => 'schedule',
            'ok' => $segundos < 120,
            'detalhe' => "Última batida há {$segundos} s ({$batida->format('H:i:s')})",
        ];
    }

    /** @return array{nome: string, icone: string, ok: bool, detalhe: string} */
    private function roboAutorizadas(RoboAutorizadas $robo): array
    {
        $item = ['nome' => 'Robô das Autorizadas', 'icone' => 'verified'];
        $pausa = $robo->pausa();
        $ultimo = CicloAutorizadas::query()->latest('iniciado_em')->first();

        return $item + match (true) {
            ! $robo->ligado() => ['ok' => true, 'detalhe' => 'Desligado neste servidor (WALLE_AUTORIZADAS_ATIVO=false)'],
            $pausa !== null => ['ok' => ! $pausa['automatica'], 'detalhe' => $pausa['motivo'].' — até '.$pausa['ate']->format('H:i')],
            $ultimo === null => ['ok' => false, 'detalhe' => 'Ligado, mas nenhum ciclo rodou ainda — o Horizon está rodando?'],
            $ultimo->erro !== null => ['ok' => false, 'detalhe' => 'Último ciclo com erro: '.$ultimo->erro],
            $ultimo->iniciado_em->lt(now()->subMinutes(40)) => ['ok' => false, 'detalhe' => 'Nenhum ciclo desde '.$ultimo->iniciado_em->format('d/m H:i').' — o Horizon está rodando?'],
            default => ['ok' => true, 'detalhe' => "Último ciclo às {$ultimo->iniciado_em->format('H:i')}: {$ultimo->consultadas} consultadas, {$ultimo->autorizadas} autorizadas"],
        };
    }
}
