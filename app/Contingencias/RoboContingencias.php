<?php

namespace App\Contingencias;

use App\Contingencias\Cliente\FalhaConexaoCliente;
use App\Models\Auditoria;
use App\Models\CicloContingencias;
use App\Models\ColetaContingencias;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Substitui contingency.crawler.php e set.history.cont.php (consultorias/api do 220).
 * O crawler legado define intervalo 10s, concorrência 3, timeout HTTP 600s, conexão 15s,
 * três retries além da tentativa inicial e watchdog 600s (crawler:11-16, 202-203, 247-255).
 * Frequência e controles do daemon ficam para a task 5.1; aqui cada Job executa um ciclo.
 * O transporte reutiliza ConectorDeCliente (timeout configurado de 10s, sessão 30s).
 * Preserva a regra do legado: Sactus só é regravado quando sua quantidade positiva muda;
 * Sactus zerado com Versatus positivo mantém o histórico Sactus anterior (set.history:439-448, 547).
 * Quando ambos zeram, limpa todo o histórico do cliente (set.history:566).
 *
 * Diferenças: mantém ciclos/coletas e auditoria locais, preserva métricas válidas em falhas,
 * não replica credenciais/configuração do legado nem altera CRT nos clientes (set.history:377-414).
 * Não escreve no Help Desk. A trava 3700s acompanha robos.retry_after e excede o Job de 3600s.
 */
class RoboContingencias
{
    public const TRAVA_SEGUNDOS = 3700;

    /** Mesmo lote de escrita do set.history.cont.php:322 e 470. */
    private const LOTE_HISTORICO = 100;

    public function __construct(private readonly ColetaIntegralContingencias $coletor) {}

    public function ligado(): bool
    {
        return (bool) config('walle.contingencias.ativo');
    }

    public function rodarCiclo(): void
    {
        $this->executarCiclo();
    }

    public function coletarCliente(int $clienteId): ResultadoContingencias
    {
        return $this->executarCiclo($clienteId);
    }

    private function executarCiclo(?int $clienteId = null): ResultadoContingencias
    {
        if (! $this->ligado()) {
            return $this->vazio('O robô de contingências está desligado.');
        }

        $ciclo = CicloContingencias::create(['iniciado_em' => now()]);
        $origem = $clienteId === null ? 'automatica' : 'manual';
        $resultado = $this->vazio();

        try {
            $clientes = $clienteId !== null ? [$clienteId] : DB::connection('help')
                ->table('informacao_tecnica_dataserver as i')
                ->join('c_cliente as c', 'c.id', '=', 'i.fk_c_cliente')
                ->orderByDesc('c.id')->distinct()->pluck('c.id')->all();

            foreach ($clientes as $id) {
                [$resultado, $status] = $this->executarCliente((int) $id, $ciclo, $origem);

                if ($status !== 'fora') {
                    $ciclo->elegiveis++;
                    $status === 'sucesso' ? $ciclo->coletados++ : $ciclo->falhas++;
                }
            }

            $ciclo->status = $ciclo->falhas > 0 ? 'concluido_com_falhas' : 'concluido';
        } catch (Throwable $erro) {
            $ciclo->status = 'falha';
            $ciclo->erro = $this->erroSeguro($erro);
            $resultado = $this->vazio($ciclo->erro);
            report(new RuntimeException($ciclo->erro));
        } finally {
            $ciclo->terminado_em = now();
            $ciclo->save();
        }

        return $resultado;
    }

    /** @return array{ResultadoContingencias, string} */
    private function executarCliente(int $clienteId, CicloContingencias $ciclo, string $origem): array
    {
        $trava = Cache::lock('contingencias:cliente:'.$clienteId, self::TRAVA_SEGUNDOS);
        $adquirida = false;
        $coleta = null;

        try {
            $adquirida = $trava->get();

            if (! $adquirida) {
                return $this->falhar($clienteId, $ciclo, $origem, null, 'O cliente está ocupado por outra coleta.');
            }

            $servidor = $this->servidor($clienteId) ?? throw FalhaConexaoCliente::semCadastroNoHelpDesk();
            $inativo = $servidor['deletado'] === 'S' || $servidor['consultoria'] === 'N'
                || DB::table('informacao_tecnica_painel_contingencias')->where('fk_c_cliente', $clienteId)
                    ->orderByDesc('id')->value('ativo_painel_contingencias') === 'N';

            if ($inativo) {
                DB::transaction(function () use ($clienteId, $ciclo, $origem): void {
                    DB::table('history_contingencia')->where('fk_c_cliente', $clienteId)->delete();
                    DB::table('painel_contingency_crawler')->where('fk_c_cliente', $clienteId)->delete();
                    Auditoria::registrar('contingencias.remover_inativo', $clienteId, [
                        'origem' => $origem, 'robo' => 'contingencias', 'ciclo_id' => $ciclo->id,
                    ]);
                });

                return [$this->vazio('O cliente está fora da coleta de contingências.'), 'fora'];
            }

            $coleta = $this->iniciarColeta($clienteId, $ciclo);
            $resultado = $this->coletor->coletar($clienteId, $servidor);

            DB::transaction(function () use ($clienteId, $resultado, $coleta, $origem): void {
                $this->gravarHistorico($clienteId, $resultado);
                $totais = [
                    'total_cliente' => count($resultado->pendentes),
                    'total_bot' => count($resultado->pendentes),
                    'total_sactus' => $resultado->totalNoPdvSactus,
                    'total_versatus' => $resultado->totalDo(Contingencia::VERSATUS),
                ];
                DB::table('painel_contingency_crawler')->upsert([
                    ['fk_c_cliente' => $clienteId, ...$totais, 'data' => now()],
                ], ['fk_c_cliente'], [...array_keys($totais), 'data']);
                $coleta->fill([...$totais, 'status' => 'sucesso', 'terminado_em' => now()])->save();
                $this->auditar($coleta, $origem);
            });

            return [$resultado, 'sucesso'];
        } catch (Throwable $erro) {
            $mensagem = $this->erroSeguro($erro);
            report(new RuntimeException($mensagem));

            return $this->falhar($clienteId, $ciclo, $origem, $coleta, $mensagem);
        } finally {
            if ($adquirida) {
                $trava->release();
            }
        }
    }

    private function iniciarColeta(int $clienteId, CicloContingencias $ciclo): ColetaContingencias
    {
        return ColetaContingencias::create(['ciclo_id' => $ciclo->id, 'fk_c_cliente' => $clienteId, 'iniciado_em' => now()]);
    }

    /** @return array{ResultadoContingencias, string} */
    private function falhar(int $clienteId, CicloContingencias $ciclo, string $origem, ?ColetaContingencias $coleta, string $mensagem): array
    {
        $coleta ??= $this->iniciarColeta($clienteId, $ciclo);

        DB::transaction(function () use ($coleta, $mensagem, $origem): void {
            $coleta->fill(['status' => 'falha', 'erro' => $mensagem, 'terminado_em' => now(),
                'total_cliente' => null, 'total_bot' => null, 'total_sactus' => null, 'total_versatus' => null])->save();
            $this->auditar($coleta, $origem);
        });

        return [$this->vazio($mensagem), 'falha'];
    }

    private function auditar(ColetaContingencias $coleta, string $origem): void
    {
        Auditoria::registrar('contingencias.coletar', $coleta->fk_c_cliente, [
            'origem' => $origem, 'robo' => 'contingencias', 'ciclo_id' => $coleta->ciclo_id,
            'coleta_id' => $coleta->id, 'status' => $coleta->status,
        ]);
    }

    /** @return array<string, mixed>|null */
    private function servidor(int $clienteId): ?array
    {
        $linha = DB::connection('help')->table('informacao_tecnica_dataserver as i')
            ->join('c_cliente as c', 'c.id', '=', 'i.fk_c_cliente')
            ->where('c.id', $clienteId)->orderByDesc('i.id')
            ->select('i.*', 'c.cnpj', 'c.deletado', 'c.consultoria')->first();

        return $linha ? array_map(fn ($valor) => is_string($valor) ? trim($valor) : $valor, (array) $linha) : null;
    }

    private function gravarHistorico(int $clienteId, ResultadoContingencias $resultado): void
    {
        if ($resultado->pendentes === []) {
            DB::table('history_contingencia')->where('fk_c_cliente', $clienteId)->delete();

            return;
        }

        foreach ([Contingencia::VERSATUS, Contingencia::SACTUS] as $software) {
            $consulta = DB::table('history_contingencia')->where('fk_c_cliente', $clienteId)->where('software', $software);
            $linhas = array_values(array_filter($resultado->pendentes, fn (Contingencia $linha): bool => $linha->software === $software));

            if ($software === Contingencia::SACTUS && ($linhas === [] || $consulta->count() === count($linhas))) {
                continue;
            }

            $consulta->delete();
            $indiceSemChave = 0;

            foreach (array_chunk($linhas, self::LOTE_HISTORICO) as $lote) {
                $valores = [];

                foreach ($lote as $linha) {
                    $chave = trim($linha->chave);
                    $numero = $linha->cupom;

                    if ($software === Contingencia::VERSATUS) {
                        $numero = (string) (int) $numero;
                        $chave = (int) substr($chave, 0, 1) > 0 ? $chave : $numero;
                    } elseif ($chave === '') {
                        $chave = $clienteId.$indiceSemChave.str_replace('.', '', (string) microtime(true));
                        $indiceSemChave++;
                    }

                    array_push($valores, $clienteId, $linha->pdv, $numero, $chave, $linha->emitidaEm, mb_substr($linha->motivo, 0, 256), $software);
                }

                DB::statement('REPLACE INTO history_contingencia (fk_c_cliente, serie, numero, chave, data_fiscal_xml, motivo, software) VALUES '
                    .implode(',', array_fill(0, count($lote), '(?,?,?,?,?,?,?)')), $valores);
            }
        }
    }

    private function erroSeguro(Throwable $erro): string
    {
        return match ($erro instanceof FalhaConexaoCliente ? $erro->codigo : '') {
            'ERRO_SEM_CADASTRO' => 'O cliente não tem cadastro técnico de servidor no Help Desk.',
            'ERRO_PARAMETROS' => 'O cadastro do servidor do cliente está incompleto.',
            'ERRO_HOST' => 'O servidor do cliente não respondeu.',
            'ERRO_MYSQL' => 'Não foi possível acessar o banco do servidor do cliente.',
            'ERRO_SEM_ACTUS' => 'O banco do Sactus não foi encontrado no servidor do cliente.',
            default => 'Não foi possível concluir a coleta de contingências do cliente.',
        };
    }

    private function vazio(?string $aviso = null): ResultadoContingencias
    {
        return new ResultadoContingencias([], [], 0, $aviso, now()->format('H:i:s'));
    }
}
