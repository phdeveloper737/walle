<?php

namespace App\Contingencias;

use App\Contingencias\Cliente\FalhaConexaoCliente;
use App\Contingencias\Cliente\TextoSemSenha;
use App\Models\Auditoria;
use App\Models\CicloContingencias;
use App\Models\ColetaContingencias;
use App\Support\ConfiguracaoRobo;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDOException;
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

    /** Quantas coletas com falha ficam guardadas por cliente (pedido do usuário de 05/11/2026). */
    private const FALHAS_GUARDADAS = 10;

    private const MENSAGEM_OCUPADO = 'O cliente está ocupado por outra coleta.';

    private const CODIGO_OCUPADO = 'CLIENTE_OCUPADO';

    public function __construct(private readonly ColetaIntegralContingencias $coletor) {}

    public function ligado(): bool
    {
        return ConfiguracaoRobo::ligado('contingencias');
    }

    public function rodarCiclo(): void
    {
        ConfiguracaoRobo::noCiclo('contingencias', fn (): ResultadoContingencias => $this->executarCiclo());
    }

    public function coletarCliente(int $clienteId): ResultadoContingencias
    {
        return ConfiguracaoRobo::noCiclo('contingencias', fn (): ResultadoContingencias => $this->executarCiclo($clienteId));
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
        return $this->coletarDoCliente($clienteId, $ciclo, $origem);
    }

    /**
     * Mantém só as últimas falhas reais do cliente, a falha "ocupado" mais recente, a última coleta que não
     * falhou e as em andamento. Roda com a trava do cliente e nunca apaga id acima do maior lido no início.
     * Uma falha aqui não derruba a coleta; os ciclos não são apagados.
     */
    private function limparColetasAntigas(int $clienteId): void
    {
        try {
            $base = fn () => DB::table('contingencias_coletas')->where('fk_c_cliente', $clienteId);
            $teto = (int) $base()->max('id');
            $ocupado = fn ($consulta) => $consulta->where('erro', self::MENSAGEM_OCUPADO)
                ->orWhere('detalhe_tecnico->codigo', self::CODIGO_OCUPADO);
            $real = fn ($consulta) => $consulta->where(fn ($q) => $q->whereNull('erro')->orWhere('erro', '<>', self::MENSAGEM_OCUPADO))
                ->where(fn ($q) => $q->whereNull('detalhe_tecnico')->orWhere('detalhe_tecnico->codigo', '<>', self::CODIGO_OCUPADO)
                    ->orWhereRaw('json_extract(detalhe_tecnico, ?) is null', ['$.codigo']));

            $falhas = $base()->where('id', '<=', $teto)->where('status', 'falha')
                ->where($real)
                ->orderByDesc('id')->limit((int) config('walle.contingencias.falhas_guardadas', self::FALHAS_GUARDADAS))
                ->pluck('id')->all();
            $ultimaOcupada = $base()->where('id', '<=', $teto)->where('status', 'falha')->where($ocupado)->max('id');
            $ultimaBoa = $base()->where('id', '<=', $teto)->where('status', 'sucesso')->max('id');

            $base()->where('id', '<=', $teto)
                ->where(fn ($consulta) => $consulta->where('status', '<>', 'em_andamento')
                    ->orWhere('iniciado_em', '<', now()->subSeconds(self::TRAVA_SEGUNDOS)))
                ->whereNotIn('id', [...$falhas, (int) $ultimaOcupada, (int) $ultimaBoa])
                ->delete();
        } catch (Throwable $erro) {
            Log::warning('Não foi possível limpar as coletas antigas de contingências do cliente '.$clienteId.': '.$erro::class);
        }
    }

    /** @return array{ResultadoContingencias, string} */
    private function coletarDoCliente(int $clienteId, CicloContingencias $ciclo, string $origem): array
    {
        $trava = Cache::lock('contingencias:cliente:'.$clienteId, self::TRAVA_SEGUNDOS);
        $adquirida = false;
        $podar = false;
        $coleta = null;
        $segredos = [];

        try {
            $adquirida = $trava->get();

            if (! $adquirida) {
                return $this->falhar($clienteId, $ciclo, $origem, null, self::MENSAGEM_OCUPADO, [
                    'codigo' => self::CODIGO_OCUPADO, 'classe' => null, 'mensagem' => self::MENSAGEM_OCUPADO,
                ]);
            }

            $podar = true;

            $servidor = $this->servidor($clienteId);
            $segredos = $this->segredosDe($servidor ?? []);
            $servidor ??= throw FalhaConexaoCliente::semCadastroNoHelpDesk();
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

                $podar = false;

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

            if (! $erro instanceof FalhaConexaoCliente) {
                report(new RuntimeException($mensagem));
            }

            return $this->falhar($clienteId, $ciclo, $origem, $coleta, $mensagem, $this->detalheTecnico($erro, $segredos));
        } finally {
            if ($adquirida) {
                if ($podar) {
                    $this->limparColetasAntigas($clienteId);
                }

                $trava->release();
            }
        }
    }

    private function iniciarColeta(int $clienteId, CicloContingencias $ciclo): ColetaContingencias
    {
        return ColetaContingencias::create(['ciclo_id' => $ciclo->id, 'fk_c_cliente' => $clienteId, 'iniciado_em' => now()]);
    }

    /**
     * @param  array<string, mixed>|null  $detalheTecnico
     * @return array{ResultadoContingencias, string}
     */
    private function falhar(int $clienteId, CicloContingencias $ciclo, string $origem, ?ColetaContingencias $coleta, string $mensagem, ?array $detalheTecnico = null): array
    {
        $coleta ??= $this->iniciarColeta($clienteId, $ciclo);

        DB::transaction(function () use ($coleta, $mensagem, $origem, $detalheTecnico): void {
            $coleta->fill(['status' => 'falha', 'erro' => $mensagem, 'detalhe_tecnico' => $detalheTecnico, 'terminado_em' => now(),
                'total_cliente' => null, 'total_bot' => null, 'total_sactus' => null, 'total_versatus' => null])->save();
            $this->auditar($coleta, $origem);
        });

        return [$this->vazio($mensagem), 'falha'];
    }

    /**
     * Tudo o que o admin precisa para entender a falha, sem senha em nenhum texto.
     *
     * @param  list<string>  $segredos
     * @return array<string, mixed>
     */
    private function detalheTecnico(Throwable $erro, array $segredos): array
    {
        $segredos = [...$segredos, (string) config('services.clientes.docker_senha_root')];
        $limpar = fn (string $texto): string => TextoSemSenha::limpar($texto, $segredos);
        $cadeia = [];

        for ($anterior = $erro->getPrevious(); $anterior !== null && count($cadeia) < 5; $anterior = $anterior->getPrevious()) {
            $cadeia[] = ['classe' => $anterior::class, 'codigo' => (string) $anterior->getCode(), 'mensagem' => $this->mensagemSegura($anterior, $limpar)];
        }

        $falha = $erro instanceof FalhaConexaoCliente ? $erro : null;

        return [
            'codigo' => $falha?->codigo,
            'classe' => $erro::class,
            'mensagem' => $this->mensagemSegura($erro, $limpar),
            'detalhe' => $falha !== null ? $limpar($falha->detalhe) : null,
            'tentativa' => $falha !== null ? $this->limparRecursivo($falha->tentativa, $limpar) : [],
            'origem' => str_replace(base_path().DIRECTORY_SEPARATOR, '', $erro->getFile()).':'.$erro->getLine(),
            'anteriores' => $cadeia,
        ];
    }

    /**
     * Só a falha de conexão tem texto próprio e seguro. Erros de banco guardam a mensagem do driver sem o trecho
     * "(Connection: ..., SQL: ...)"; qualquer outra exceção vira texto genérico (a mensagem pode ter dados do cliente).
     */
    private function mensagemSegura(Throwable $erro, \Closure $limpar): string
    {
        if ($erro instanceof FalhaConexaoCliente) {
            return $limpar($erro->getMessage());
        }

        if ($erro instanceof QueryException || $erro instanceof PDOException) {
            $mensagem = $erro->getMessage();
            $corte = strpos($mensagem, ' (Connection:');

            return $limpar($corte === false ? $mensagem : substr($mensagem, 0, $corte));
        }

        return 'Erro inesperado; o texto original não é guardado por segurança.';
    }

    /**
     * @param  array<array-key, mixed>  $dados
     * @return array<array-key, mixed>
     */
    private function limparRecursivo(array $dados, \Closure $limpar): array
    {
        return array_map(fn (mixed $valor): mixed => match (true) {
            is_array($valor) => $this->limparRecursivo($valor, $limpar),
            is_string($valor) => $limpar($valor),
            default => $valor,
        }, $dados);
    }

    /**
     * @param  array<string, mixed>  $servidor
     * @return list<string>
     */
    private function segredosDe(array $servidor): array
    {
        return array_values(array_filter(
            array_map(fn (string $campo): string => trim((string) ($servidor[$campo] ?? '')), ['senha_mysql', 'senha_mysql_docker']),
            fn (string $senha): bool => $senha !== '',
        ));
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
