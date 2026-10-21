<?php

namespace App\Autorizadas;

use App\Sefaz\ChaveDeAcesso;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Tudo o que o walle sabe de uma chave e por que o robô vai (ou não) consultá-la
 * (substitui o autorizadas.debug.php antigo).
 */
final readonly class DiagnosticoChave
{
    /**
     * @param  list<array{cliente: int, serie: string, numero: string, data_fiscal: string|null, software: string, motivo: string}>  $historico
     * @param  array{cliente: int, autorizada: bool, referencia: string|null, ultima_consulta: string|null}|null  $auth
     * @param  array{situacao: 'ok'|'espera'|'fora', texto: string}  $fila
     */
    public function __construct(
        public ChaveDeAcesso $chave,
        public ?string $problema,
        public array $historico,
        public ?array $auth,
        public array $fila,
    ) {}

    public static function de(ChaveDeAcesso $chave, RoboAutorizadas $robo): self
    {
        $historico = DB::table('history_contingencia')
            ->where('chave', $chave->chave)
            ->orderByDesc('data_fiscal_xml')
            ->limit(20)
            ->get()
            ->map(fn (object $l): array => [
                'cliente' => (int) $l->fk_c_cliente,
                'serie' => trim((string) $l->serie),
                'numero' => trim((string) $l->numero),
                'data_fiscal' => $l->data_fiscal_xml,
                'software' => trim((string) $l->software),
                'motivo' => trim((string) $l->motivo),
            ])
            ->all();

        $linha = DB::table('history_contingencia_auth')->where('chave', $chave->chave)->first();
        $auth = $linha === null ? null : [
            'cliente' => (int) $linha->fk_c_cliente,
            'autorizada' => $linha->autorizada === 'S',
            'referencia' => $linha->referencia,
            'ultima_consulta' => $linha->data_consulta_sefaz_bot,
        ];

        $problema = $chave->problema((string) config('services.sefaz.uf', '52'));

        return new self($chave, $problema, $historico, $auth, self::fila($chave, $historico, $auth, $robo));
    }

    /** Cliente a quem a chave pertence pelo que o walle já tem (0 = não sabe). */
    public function clienteConhecido(): int
    {
        return ($this->auth['cliente'] ?? 0) ?: ($this->historico[0]['cliente'] ?? 0);
    }

    /**
     * Mesmas condições do robô, avaliadas uma a uma, para dizer em português o que vai acontecer.
     *
     * @param  list<array{cliente: int, serie: string, numero: string, data_fiscal: string|null, software: string, motivo: string}>  $historico
     * @param  array{cliente: int, autorizada: bool, referencia: string|null, ultima_consulta: string|null}|null  $auth
     * @return array{situacao: 'ok'|'espera'|'fora', texto: string}
     */
    private static function fila(ChaveDeAcesso $chave, array $historico, ?array $auth, RoboAutorizadas $robo): array
    {
        if (strlen($chave->chave) !== 44) {
            return ['situacao' => 'fora', 'texto' => 'Chave inválida: o robô não consulta.'];
        }

        if ($auth === null) {
            if ($historico === []) {
                return ['situacao' => 'fora', 'texto' => 'Não está no histórico de contingências: o robô não conhece esta chave.'];
            }

            $referencia = substr((string) $historico[0]['data_fiscal'], 0, 10) ?: $chave->referencia();

            return $referencia !== null && $referencia < $robo->corteRetencao()
                ? ['situacao' => 'fora', 'texto' => 'Está no histórico, mas já passou da retenção: o robô não semeia.']
                : ['situacao' => 'espera', 'texto' => 'Está no histórico e ainda não foi semeada: entra num dos próximos ciclos.'];
        }

        // O primeiro passo de cada ciclo apaga essas, autorizadas ou não
        if ($auth['referencia'] !== null && $auth['referencia'] < $robo->corteRetencao()) {
            return ['situacao' => 'fora', 'texto' => 'A referência passou da retenção de '.config('walle.autorizadas.dias_retencao').' dias: sai da base no próximo ciclo.'];
        }

        if ($auth['autorizada']) {
            return ['situacao' => 'ok', 'texto' => 'Já autorizada: o robô não consulta mais.'];
        }

        if ($chave->uf() !== '52') {
            return ['situacao' => 'fora', 'texto' => 'De outra UF ('.ChaveDeAcesso::siglaUf($chave->uf()).'): o robô só consulta na Sefaz GO.'];
        }

        if ($auth['ultima_consulta'] !== null) {
            $volta = Carbon::parse($auth['ultima_consulta'])->addHours((int) config('walle.autorizadas.intervalo_horas'));

            if ($volta->isFuture()) {
                return ['situacao' => 'espera', 'texto' => 'Não autorizada, consultada há pouco: volta para a fila às '.$volta->format('H:i').'.'];
            }
        }

        return ['situacao' => 'espera', 'texto' => 'Não autorizada e na fila: será consultada num dos próximos ciclos.'];
    }
}
