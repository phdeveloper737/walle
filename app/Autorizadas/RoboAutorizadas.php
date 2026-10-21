<?php

namespace App\Autorizadas;

use App\Models\CicloAutorizadas;
use App\Sefaz\ChaveDeAcesso;
use App\Sefaz\ConsultaSituacao;
use App\Sefaz\ResultadoConsulta;
use App\Sefaz\SituacaoNfce;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Robô das Autorizadas (substitui o tools/services/autorizadas.php do sistema antigo).
 *
 * Cada ciclo, igual ao antigo:
 *   1. apaga da history_contingencia_auth as chaves com referência além da retenção (73 dias);
 *   2. semeia as chaves novas do history_contingencia (marcadas como não autorizadas);
 *   3. consulta na Sefaz GO até 250 pendentes (nunca consultadas ou consultadas há mais de 3 h);
 *   4. grava autorizada S/N e a hora da consulta, de 50 em 50.
 *
 * Diferenças: uma conexão TLS para o ciclo inteiro; se a Sefaz acusar consumo indevido (656),
 * para o ciclo e fica parado por um tempo (o antigo seguia consultando e agravava o bloqueio);
 * e não semeia de novo as chaves que já passaram da retenção (o antigo apagava, semeava de novo
 * e gastava consultas com elas a cada volta).
 */
class RoboAutorizadas
{
    private const CHAVE_PAUSA = 'autorizadas:pausa';

    private const CHAVE_CURSOR = 'autorizadas:cursor-semeadura';

    public function __construct(private readonly ConsultaSituacao $sefaz) {}

    public function ligado(): bool
    {
        return (bool) config('walle.autorizadas.ativo');
    }

    /**
     * Pausa em andamento, ou null. automatica = a Sefaz acusou consumo indevido (o certificado
     * está bloqueado: nem a consulta à mão do painel pode rodar); senão foi alguém pelo painel.
     *
     * @return array{ate: Carbon, motivo: string, automatica: bool}|null
     */
    public function pausa(): ?array
    {
        $pausa = Cache::get(self::CHAVE_PAUSA);

        // O Valkey devolve número como texto, por isso is_numeric
        if (! is_array($pausa) || ! is_numeric($pausa['ate'] ?? null) || (int) $pausa['ate'] <= now()->getTimestamp()) {
            return null;
        }

        return [
            'ate' => Carbon::createFromTimestamp((int) $pausa['ate'], config('app.timezone')),
            'motivo' => (string) ($pausa['motivo'] ?? ''),
            'automatica' => (bool) ($pausa['automatica'] ?? false),
        ];
    }

    public function pausadoAte(): ?Carbon
    {
        return $this->pausa()['ate'] ?? null;
    }

    public function pausar(Carbon $ate, string $motivo = '', bool $automatica = false): void
    {
        Cache::put(self::CHAVE_PAUSA, ['ate' => $ate->getTimestamp(), 'motivo' => $motivo, 'automatica' => $automatica], $ate);
    }

    /** Pausa automática por consumo indevido (656): o robô para e a consulta à mão também. */
    public function pausarPorConsumoIndevido(string $onde): void
    {
        $this->pausar(
            now()->addMinutes((int) config('walle.autorizadas.pausa_consumo_indevido_minutos')),
            "A Sefaz acusou consumo indevido (656) {$onde}",
            automatica: true,
        );
    }

    public function retomar(): void
    {
        Cache::forget(self::CHAVE_PAUSA);
    }

    /** Data de referência a partir da qual as chaves são mantidas (as anteriores são apagadas). */
    public function corteRetencao(): string
    {
        return now()->subDays((int) config('walle.autorizadas.dias_retencao'))->toDateString();
    }

    public function rodarCiclo(): CicloAutorizadas
    {
        $config = config('walle.autorizadas');
        $ciclo = CicloAutorizadas::create(['iniciado_em' => now()]);

        try {
            $ciclo->removidas = $this->limparAntigas();
            $ciclo->semeadas = $this->semear((int) $config['semear_por_leitura'], (int) $config['semear_leituras_por_ciclo']);

            $fila = $this->pendentes((int) $config['limite_ciclo'], (int) $config['intervalo_horas']);
            $ciclo->na_fila = count($fila);
            $ciclo->save();

            $paraGravar = [];

            $this->sefaz->consultar(
                array_keys($fila),
                function (ResultadoConsulta $resultado) use ($ciclo, $fila, &$paraGravar, $config): void {
                    $ciclo->consultadas++;

                    if ($resultado->situacao === SituacaoNfce::Erro) {
                        $ciclo->falhas++;

                        return;
                    }

                    $ciclo->consumo_indevido = $ciclo->consumo_indevido || $resultado->consumoIndevido();
                    $ciclo->autorizadas += $resultado->autorizada() ? 1 : 0;
                    $paraGravar[] = [...$fila[$resultado->chave], 'chave' => $resultado->chave, 'autorizada' => $resultado->autorizada()];

                    if (count($paraGravar) >= (int) $config['gravar_a_cada']) {
                        $ciclo->gravadas += $this->gravar($paraGravar);
                        $paraGravar = [];
                        // O painel acompanha o ciclo em andamento por aqui
                        $ciclo->save();
                    }
                },
                pausaMs: (int) $config['pausa_entre_chaves'] * 1000,
            );

            $ciclo->gravadas += $this->gravar($paraGravar);

            if ($ciclo->consumo_indevido) {
                $this->pausarPorConsumoIndevido('no ciclo #'.$ciclo->id);
            }
        } catch (Throwable $erro) {
            report($erro);
            $ciclo->erro = mb_substr($erro->getMessage(), 0, 500);
        } finally {
            $ciclo->terminado_em = now();
            $ciclo->save();
        }

        return $ciclo;
    }

    /**
     * Grava uma chave que voltou autorizada numa consulta feita à mão pelo painel
     * (mesmas regras do gravaAutorizadas do consulta.nfce.php antigo: só grava autorizada,
     * não troca a referência que já existe e não apaga o cliente com 0).
     */
    public function registrarAutorizada(ChaveDeAcesso $chave, int $clienteId, ?string $referencia): void
    {
        DB::statement(
            'INSERT INTO history_contingencia_auth (fk_c_cliente, chave, autorizada, referencia, data_consulta_sefaz_bot)
             VALUES (?, ?, \'S\', ?, ?) AS novo
             ON DUPLICATE KEY UPDATE
                fk_c_cliente = IF(novo.fk_c_cliente > 0, novo.fk_c_cliente, history_contingencia_auth.fk_c_cliente),
                autorizada = \'S\',
                referencia = IFNULL(history_contingencia_auth.referencia, novo.referencia),
                data_consulta_sefaz_bot = novo.data_consulta_sefaz_bot',
            [$clienteId, $chave->chave, $referencia ?? $chave->referencia() ?? now()->toDateString(), now()->toDateTimeString()],
        );
    }

    private function limparAntigas(): int
    {
        return DB::table('history_contingencia_auth')
            ->where('referencia', '<', $this->corteRetencao())
            ->delete();
    }

    /**
     * Chaves novas do history_contingencia viram pendentes (autorizada = 'N') na history_contingencia_auth.
     * Lê em ordem de chave a partir de onde parou no ciclo anterior.
     */
    private function semear(int $porLeitura, int $leiturasPorCiclo): int
    {
        $cursor = (string) Cache::get(self::CHAVE_CURSOR, '');
        $hoje = now()->toDateString();
        $corte = $this->corteRetencao();
        $semeadas = 0;
        $lidas = 0;

        for ($leitura = 0; $leitura < $leiturasPorCiclo; $leitura++) {
            $linhas = DB::table('history_contingencia')
                ->whereNotNull('chave')
                ->where('chave', '>', $cursor)
                ->orderBy('chave')
                ->limit($porLeitura)
                ->get(['fk_c_cliente', 'chave', 'data_fiscal_xml']);

            $lidas = $linhas->count();

            if ($lidas === 0) {
                break;
            }

            $candidatas = [];

            foreach ($linhas as $linha) {
                $chave = ChaveDeAcesso::de($linha->chave);
                $referencia = $this->referencia($linha->data_fiscal_xml, $chave) ?? $hoje;

                // Já passou da retenção: seria apagada no próximo ciclo (e consultada à toa até lá)
                if (strlen($chave->chave) === 44 && $referencia >= $corte) {
                    $candidatas[$chave->chave] = [
                        'fk_c_cliente' => (int) $linha->fk_c_cliente,
                        'chave' => $chave->chave,
                        'autorizada' => 'N',
                        'referencia' => $referencia,
                        'data_consulta_sefaz_bot' => null,
                    ];
                }
            }

            $semeadas += DB::table('history_contingencia_auth')->insertOrIgnore(array_values($candidatas));
            $cursor = (string) $linhas->last()->chave;

            if ($lidas < $porLeitura) {
                break;
            }
        }

        // Chegou ao fim do histórico: no próximo ciclo recomeça do início (pega as que entraram depois)
        Cache::forever(self::CHAVE_CURSOR, $lidas < $porLeitura ? '' : $cursor);

        return $semeadas;
    }

    /**
     * Não autorizadas de GO (52) que nunca foram consultadas ou foram há mais de N horas,
     * as consultadas há mais tempo primeiro.
     *
     * @return array<string, array{fk_c_cliente: int, referencia: string|null}> chave => dados
     */
    private function pendentes(int $limite, int $intervaloHoras): array
    {
        $linhas = DB::table('history_contingencia_auth')
            ->where('autorizada', 'N')
            ->where('chave', 'like', '52%')
            ->where(fn ($q) => $q->whereNull('data_consulta_sefaz_bot')
                ->orWhere('data_consulta_sefaz_bot', '<=', now()->subHours($intervaloHoras)))
            ->orderBy('data_consulta_sefaz_bot')
            ->limit($limite)
            ->get(['fk_c_cliente', 'chave', 'referencia']);

        $fila = [];

        foreach ($linhas as $linha) {
            $chave = ChaveDeAcesso::de($linha->chave);

            if (strlen($chave->chave) === 44) {
                $fila[$chave->chave] ??= [
                    'fk_c_cliente' => (int) $linha->fk_c_cliente,
                    'referencia' => $this->referencia($linha->referencia, $chave),
                ];
            }
        }

        return $fila;
    }

    /**
     * Grava as consultas. Nunca volta uma autorizada para "não autorizada" e a referência
     * é a mesma lida da fila (mesmas regras do flushBuffers do sistema antigo).
     *
     * @param  list<array{fk_c_cliente: int, referencia: string|null, chave: string, autorizada: bool}>  $consultas
     */
    private function gravar(array $consultas): int
    {
        if ($consultas === []) {
            return 0;
        }

        $valores = [];
        $parametros = [];
        // A hora do walle (fuso America/Sao_Paulo), não a do relógio do MySQL
        $agora = now()->toDateTimeString();

        foreach ($consultas as $consulta) {
            $valores[] = '(?, ?, ?, ?, ?)';
            array_push($parametros, $consulta['fk_c_cliente'], $consulta['chave'], $consulta['autorizada'] ? 'S' : 'N', $consulta['referencia'] ?? now()->toDateString(), $agora);
        }

        DB::statement(
            'INSERT INTO history_contingencia_auth (fk_c_cliente, chave, autorizada, referencia, data_consulta_sefaz_bot)
             VALUES '.implode(', ', $valores).' AS novo
             ON DUPLICATE KEY UPDATE
                fk_c_cliente = IF(novo.fk_c_cliente > 0, novo.fk_c_cliente, history_contingencia_auth.fk_c_cliente),
                autorizada = IF(novo.autorizada = \'S\', \'S\', history_contingencia_auth.autorizada),
                referencia = IFNULL(novo.referencia, history_contingencia_auth.referencia),
                data_consulta_sefaz_bot = novo.data_consulta_sefaz_bot',
            $parametros,
        );

        return count($consultas);
    }

    /** Data fiscal (se válida) ou o mês de emissão que está na própria chave. */
    private function referencia(mixed $data, ChaveDeAcesso $chave): ?string
    {
        $data = substr((string) $data, 0, 10);

        if ($data !== '' && ! str_starts_with($data, '0000')) {
            return $data;
        }

        return $chave->referencia();
    }
}
