<?php

namespace App\Ferramentas;

use App\Contingencias\Cliente\ConectorDeCliente;
use App\Contingencias\Cliente\FalhaConexaoCliente;
use App\Models\Auditoria;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PDOException;
use Throwable;

/**
 * Substitui task.scheduler.bot.php (programacao/api do 220): executa cada query ativa de
 * task_scheduler_querys no banco de cada cliente e grava o resultado em task_scheduler_querys_log.
 *
 * Regras do 220 (task.scheduler.bot.php): queries com deletado = 'N' e ativo = 'S' (74); clientes do Help Desk com
 * c_cliente.deletado = 'N', um por id, id decrescente, LIMIT 3000 (91-98); até 20 clientes com conexão aberta por query
 * e por chamada (146, o contador só sobe quando conecta); ids 18, 1156 e 1470 nunca rodam (122-124); cliente com log
 * OK ('T0s=' = base64 de "OK") nunca roda de novo; tipo 1 (padrão, 22) também pula cliente com log começando por
 * FALHA e o tipo 2 (23-25, chamado com menos frequência) repete só as FALHA; janela de horário 00:00:01 a 23:59:59
 * (38-40); fora da janela só faz a manutenção dos logs duplicados (279-358). Query: base64, sem crase, aspas duplas
 * nem ponto e vírgula (213-217). Log: base64("OK") ou base64("Erro: <erro do MySQL>") quando a query roda; texto
 * "FALHA AO CONECTAR NO CLIENTE: ..." quando não conecta (183, 202, 241). Timeout de conexão 3 s e verificação
 * de ping do 220 (171, 33) viram o ConectorDeCliente (timeout configurado de 10 s, porta conferida antes).
 * O intervalo entre chamadas fica para a agenda da task 5.1.
 *
 * Diferenças: o filtro de "percorridos" é limitado à query (no 220 o OR sem parênteses fazia o FALHA/não-FALHA de
 * qualquer query valer para todas); a mensagem de falha de conexão não leva usuário nem senha; sem
 * queryExecute.log em arquivo; cada execução passa por Auditoria::registrar() sem a query nem o texto do erro;
 * Cache::lock por cliente; falha inesperada de um cliente é reportada e vira FALHA sem derrubar o ciclo. Banco do
 * cliente: o do ConectorDeCliente (cadastro do servidor). Não escreve no Help Desk (só lê clientes). As queries são
 * as cadastradas por admin na tela de Ferramentas; o robô só roda ligado (config walle.tarefas.ativo).
 */
class RoboTarefas
{
    /** Clientes com conexão aberta por query e por chamada (task.scheduler.bot.php:146). */
    public const LIMITE_CLIENTES_POR_QUERY = 20;

    /** Clientes lidos do Help Desk (task.scheduler.bot.php:98). */
    public const LIMITE_CLIENTES = 3000;

    /** Clientes que nunca rodam (task.scheduler.bot.php:122-124). */
    public const CLIENTES_IGNORADOS = [18, 1156, 1470];

    /** Janela padrão (task.scheduler.bot.php:38-40); config walle.tarefas.hora_inicio e hora_fim. */
    public const HORA_INICIO = '00:00:01';

    public const HORA_FIM = '23:59:59';

    public const TRAVA_SEGUNDOS = 600;

    private const LOG_OK = 'OK';

    private const CARACTERES_REMOVIDOS = ['`', '"', ';'];

    public function __construct(private readonly ConectorDeCliente $conector) {}

    public function ligado(): bool
    {
        return (bool) config('walle.tarefas.ativo');
    }

    /**
     * @param  int  $tipoBot  1 = pula cliente com FALHA; 2 = repete só os com FALHA
     * @param  int|null  $clienteId  restringe a um cliente (parâmetro id do 220)
     */
    public function rodarPendentes(int $tipoBot = 1, ?int $clienteId = null): void
    {
        if (! $this->ligado()) {
            return;
        }

        if (! $this->dentroDaJanela()) {
            $this->limparDuplicados();

            return;
        }

        $queries = DB::table('task_scheduler_querys')->where('deletado', 'N')->where('ativo', 'S')->orderBy('id')->get();

        if ($queries->isEmpty()) {
            return;
        }

        $clientes = $this->clientes($clienteId);

        foreach ($queries as $query) {
            $percorridos = $this->percorridos((int) $query->id, $tipoBot);
            $conectados = 0;

            foreach ($clientes as $id => $servidor) {
                if ($conectados >= self::LIMITE_CLIENTES_POR_QUERY) {
                    break;
                }

                if (isset($percorridos[$id]) || in_array($id, self::CLIENTES_IGNORADOS, true)) {
                    continue;
                }

                if ($this->executar((int) $query->id, (string) $query->query_execute, $id, $servidor)) {
                    $conectados++;
                }
            }
        }
    }

    private function dentroDaJanela(): bool
    {
        $agora = now()->format('H:i:s');

        return $agora >= (string) config('walle.tarefas.hora_inicio', self::HORA_INICIO)
            && $agora <= (string) config('walle.tarefas.hora_fim', self::HORA_FIM);
    }

    /**
     * Um cliente por id, com a primeira linha do cadastro do servidor (GROUP BY c.id do 220).
     *
     * @return array<int, array<string, mixed>>
     */
    private function clientes(?int $clienteId): array
    {
        $linhas = DB::connection('help')->table('informacao_tecnica_dataserver as i')
            ->join('c_cliente as c', 'c.id', '=', 'i.fk_c_cliente')
            ->where('c.deletado', 'N')
            ->when($clienteId !== null && $clienteId > 0, fn ($consulta) => $consulta->where('c.id', $clienteId))
            ->orderByDesc('c.id')->orderBy('i.id')
            ->select('i.*', 'c.id as id_cliente', 'c.cnpj', 'c.deletado', 'c.consultoria')
            ->get();

        $clientes = [];

        foreach ($linhas as $linha) {
            $id = (int) $linha->id_cliente;

            if ($id > 0 && ! isset($clientes[$id]) && count($clientes) < self::LIMITE_CLIENTES) {
                $clientes[$id] = array_map(fn ($valor) => is_string($valor) ? trim($valor) : $valor, (array) $linha);
            }
        }

        return $clientes;
    }

    /** @return array<int, true> clientes que esta query não deve rodar de novo (task.scheduler.bot.php:126-134) */
    private function percorridos(int $queryId, int $tipoBot): array
    {
        $consulta = DB::table('task_scheduler_querys_log')->where('fk_task_scheduler_querys', $queryId)
            ->where(function ($filtro) use ($tipoBot): void {
                $filtro->whereRaw('TRIM(log_query) = ?', [base64_encode(self::LOG_OK)])
                    ->orWhereRaw($tipoBot === 2 ? "SUBSTRING(log_query, 1, 5) <> 'FALHA'" : "SUBSTRING(log_query, 1, 5) = 'FALHA'");
            });

        $ids = [];

        foreach ($consulta->pluck('fk_c_cliente') as $id) {
            $ids[(int) $id] = true;
        }

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $servidor
     * @return bool true quando conectou no cliente (conta no limite de 20)
     */
    private function executar(int $queryId, string $queryExecute, int $clienteId, array $servidor): bool
    {
        $trava = Cache::lock('tarefas:cliente:'.$clienteId, self::TRAVA_SEGUNDOS);

        if (! $trava->get()) {
            return false;
        }

        try {
            try {
                $conexao = $this->conector->conectar($clienteId, $servidor);
            } catch (Throwable $e) {
                if (! $e instanceof FalhaConexaoCliente) {
                    report($e);
                }

                $motivo = $e instanceof FalhaConexaoCliente ? ': '.$e->getMessage() : '';
                $this->gravar($queryId, $clienteId, mb_substr('FALHA AO CONECTAR NO CLIENTE'.$motivo, 0, 500), 'falha');

                return false;
            }

            try {
                $erro = $this->rodarNoCliente($conexao->conexao, $queryExecute);
            } finally {
                $conexao->desconectar();
            }

            $this->gravar(
                $queryId,
                $clienteId,
                base64_encode($erro === null ? self::LOG_OK : 'Erro: '.mb_substr($erro, 0, 2500)),
                $erro === null ? 'ok' : 'erro',
            );

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        } finally {
            $trava->release();
        }
    }

    /** @return string|null null quando a query rodou; senão a mensagem do MySQL */
    private function rodarNoCliente(Connection $conexao, string $queryExecute): ?string
    {
        $sql = str_replace(self::CARACTERES_REMOVIDOS, '', (string) base64_decode(trim($queryExecute)));

        try {
            $conexao->statement($sql);
        } catch (Throwable $e) {
            $previo = $e->getPrevious();

            return $previo instanceof PDOException && isset($previo->errorInfo[2])
                ? (string) $previo->errorInfo[2]
                : 'falha ao executar a query';
        }

        return null;
    }

    private function gravar(int $queryId, int $clienteId, string $log, string $resultado): void
    {
        DB::transaction(function () use ($queryId, $clienteId, $log, $resultado): void {
            DB::table('task_scheduler_querys_log')->insert([
                'fk_task_scheduler_querys' => $queryId, 'fk_c_cliente' => $clienteId, 'log_query' => $log,
            ]);

            Auditoria::registrar('tarefas.executar', $clienteId, [
                'origem' => 'automatica', 'robo' => 'tarefas', 'fk_task_scheduler_querys' => $queryId, 'resultado' => $resultado,
            ]);
        });
    }

    /** Fora da janela (task.scheduler.bot.php:291-345): apaga logs repetidos e mantém um, sem tocar nos OK. */
    private function limparDuplicados(): void
    {
        $ok = base64_encode(self::LOG_OK);

        $grupos = DB::table('task_scheduler_querys_log')
            ->whereRaw('TRIM(log_query) <> ?', [$ok])
            ->groupBy('fk_task_scheduler_querys', 'fk_c_cliente', 'log_query')
            ->havingRaw('COUNT(*) > 1')
            ->select('fk_task_scheduler_querys', 'fk_c_cliente', 'log_query')
            ->get();

        foreach ($grupos as $grupo) {
            DB::transaction(function () use ($grupo, $ok): void {
                $filtro = fn () => DB::table('task_scheduler_querys_log')
                    ->where('fk_task_scheduler_querys', $grupo->fk_task_scheduler_querys)
                    ->where('fk_c_cliente', $grupo->fk_c_cliente);

                $filtro()->whereRaw('TRIM(log_query) = ?', [$grupo->log_query])->delete();

                if (! $filtro()->whereRaw('TRIM(log_query) = ?', [$ok])->exists()) {
                    DB::table('task_scheduler_querys_log')->insert([
                        'fk_task_scheduler_querys' => $grupo->fk_task_scheduler_querys,
                        'fk_c_cliente' => $grupo->fk_c_cliente,
                        'log_query' => $grupo->log_query,
                    ]);
                }
            });
        }
    }
}
