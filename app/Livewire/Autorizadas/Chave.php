<?php

namespace App\Livewire\Autorizadas;

use App\Autorizadas\DiagnosticoChave;
use App\Autorizadas\RoboAutorizadas;
use App\Contingencias\ListaDeClientes;
use App\Models\Auditoria;
use App\Sefaz\ChaveDeAcesso;
use App\Sefaz\ConsultaSituacao;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Aba Consultar chave (substitui o autorizadas.debug.php): o que o walle sabe da chave,
 * se o robô vai consultá-la e uma consulta na Sefaz feita na hora.
 *
 * @property-read DiagnosticoChave|null $diagnostico
 */
class Chave extends Component
{
    /** Consultas à mão por minuto, somando todo mundo: o certificado é um só. */
    public const CONSULTAS_POR_MINUTO = 10;

    #[Url(as: 'chave', except: '')]
    public string $entrada = '';

    /** @var array{situacao: string, ok: bool, cStat: string|null, motivo: string, protocolo: string|null, recebida_em: string|null, gravou: bool}|null */
    public ?array $resultado = null;

    public ?string $erro = null;

    public function verificar(): void
    {
        $this->entrada = ChaveDeAcesso::de($this->entrada)->chave;
        $this->resultado = null;
        $this->erro = null;
        unset($this->diagnostico);
    }

    public function consultarSefaz(ConsultaSituacao $sefaz, RoboAutorizadas $robo): void
    {
        $this->erro = null;
        $diagnostico = $this->diagnostico;

        if ($diagnostico === null || $diagnostico->problema !== null) {
            $this->erro = $diagnostico->problema ?? 'Informe a chave.';

            return;
        }

        $pausa = $robo->pausa();

        if ($pausa !== null && $pausa['automatica']) {
            $this->erro = "A Sefaz bloqueou o certificado por consumo indevido. Tente depois das {$pausa['ate']->format('H:i')}.";

            return;
        }

        if (RateLimiter::tooManyAttempts('autorizadas:consulta-manual', self::CONSULTAS_POR_MINUTO)) {
            $this->erro = 'Muitas consultas seguidas (o certificado é um só). Espere '.RateLimiter::availableIn('autorizadas:consulta-manual').' s.';

            return;
        }

        RateLimiter::hit('autorizadas:consulta-manual', 60);

        $r = $sefaz->consultar([$diagnostico->chave->chave], pausaMs: 0)[0];
        $gravou = false;

        if ($r->consumoIndevido()) {
            $robo->pausarPorConsumoIndevido('numa consulta à mão do painel');
        }

        if ($r->autorizada()) {
            $robo->registrarAutorizada($diagnostico->chave, $diagnostico->clienteConhecido() ?: $this->clientePeloCnpj($diagnostico->chave), $this->referencia($diagnostico));
            $gravou = true;
        }

        Auditoria::registrar('autorizadas.consulta_sefaz', $diagnostico->clienteConhecido() ?: null, ['chave' => $r->chave, 'cStat' => $r->cStat]);

        $this->resultado = [
            'situacao' => $r->situacao->rotulo(),
            'ok' => $r->autorizada(),
            'cStat' => $r->cStat,
            'motivo' => $r->motivo,
            'protocolo' => $r->protocolo,
            'recebida_em' => $r->recebidaEm,
            'gravou' => $gravou,
        ];
        unset($this->diagnostico);
    }

    #[Computed]
    public function diagnostico(): ?DiagnosticoChave
    {
        $chave = ChaveDeAcesso::de($this->entrada);

        return $chave->chave === '' ? null : DiagnosticoChave::de($chave, app(RoboAutorizadas::class));
    }

    public function nomeCliente(int $id): string
    {
        return $id === 0 ? 'não identificado' : trim((app(ListaDeClientes::class)->nomes()[$id] ?? '').' #'.$id);
    }

    public function render(): View
    {
        return view('livewire.autorizadas.chave');
    }

    /** Referência que vai para a base: a que já está lá, a data fiscal do histórico ou o mês da chave. */
    private function referencia(DiagnosticoChave $d): ?string
    {
        $fiscal = substr((string) ($d->historico[0]['data_fiscal'] ?? ''), 0, 10);

        return $d->auth['referencia'] ?? ($fiscal !== '' ? $fiscal : null);
    }

    /** Como o consulta.nfce.php antigo: chave que o walle não conhece tem o cliente achado pelo CNPJ no Help Desk. */
    private function clientePeloCnpj(ChaveDeAcesso $chave): int
    {
        try {
            foreach (app(ListaDeClientes::class)->todos() as $cliente) {
                if (preg_replace('/\D/', '', $cliente->cnpj) === $chave->cnpj()) {
                    return $cliente->id;
                }
            }
        } catch (QueryException $e) {
            report($e);
        }

        return 0;
    }
}
