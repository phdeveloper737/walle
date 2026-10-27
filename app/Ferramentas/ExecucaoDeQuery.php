<?php

namespace App\Ferramentas;

use App\Contingencias\Cliente\ConectorDeCliente;
use App\Contingencias\Cliente\ConexaoComCliente;
use App\Contingencias\Cliente\FalhaConexaoCliente;
use App\Models\Auditoria;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * SQL manual de admin no banco do Sactus de um cliente (substitui execute.query.php e execute.query.available.php do 220).
 *
 * Ordem: (1) Gate admin e (2) confirmação de senha válida na sessão, conferidos antes de tudo; sem eles nada é lido
 * nem enviado e a recusa é auditada sem o SQL. (3) Uma instrução só (InstrucaoSql). (4) Cadastro do servidor ao vivo
 * no Help Desk (só leitura) e conexão pelo ConectorDeCliente. Leitura devolve colunas e até
 * config('walle.ferramentas.query_limite_linhas', 1000) linhas (truncado sinalizado); escrita devolve as linhas afetadas.
 * Cada execução vai para a Auditoria com o SQL mascarado (textos e números trocados por "?", até 500 caracteres).
 *
 * Diferenças do 220: qualquer SQL, inclusive SELECT, só para admin com senha confirmada (no 220 o SELECT era livre e
 * a escrita ficava presa a dois ids fixos); sem host, usuário ou senha fixos e sem devolvê-los no erro
 * (execute.query.available.php:143); o erro do MySQL vai para a tela sem dados da conexão, sem o SQL e sem o trecho
 * "near '...'"; a instrução roda no banco do Sactus resolvido pelo ConectorDeCliente (no 220, o banco com o nome do
 * usuário MySQL); uma instrução por chamada; nada é gravado no Help Desk.
 */
class ExecucaoDeQuery
{
    public const ACAO = 'ferramentas.executar_query';

    public const LIMITE_LINHAS = 1000;

    /** Tempo máximo de um SELECT no servidor do cliente (MAX_EXECUTION_TIME do MySQL, em ms). */
    public const TEMPO_MAXIMO_MS = 30000;

    public function __construct(private readonly ConectorDeCliente $conector) {}

    public function executar(int $clienteId, string $sql): ResultadoQuery
    {
        if (! Gate::allows('admin')) {
            $this->auditar($clienteId, ['resultado' => 'recusado', 'motivo' => 'nao_admin']);

            return ResultadoQuery::recusa('Só os admins do walle podem executar SQL nos clientes.');
        }

        if (! $this->senhaConfirmada()) {
            $this->auditar($clienteId, ['resultado' => 'recusado', 'motivo' => 'senha_nao_confirmada']);

            return ResultadoQuery::recusa('Confirme a sua senha para executar SQL no cliente.', pedeSenha: true);
        }

        $instrucao = InstrucaoSql::analisar($sql);

        if ($instrucao === null) {
            $this->auditar($clienteId, ['resultado' => 'recusado', 'motivo' => 'sql_invalido']);

            return ResultadoQuery::recusa('Envie uma instrução SQL por vez, completa (sem ";" no meio, texto ou comentário sem fim nem comentário /*! */). Nada foi enviado ao cliente.');
        }

        $tipo = $instrucao->leitura ? 'leitura' : 'escrita';
        $resultado = $this->rodar($clienteId, $instrucao);

        $this->auditar($clienteId, array_filter([
            'tipo' => $tipo,
            'resultado' => $resultado->ok ? 'ok' : 'erro',
            'linhas' => $resultado->ok && $instrucao->leitura ? count($resultado->linhas) : null,
            'truncado' => $resultado->truncado ?: null,
            'afetadas' => $resultado->afetadas,
            'sql' => $instrucao->mascarada,
        ], fn (mixed $valor): bool => $valor !== null));

        return $resultado;
    }

    /**
     * Mesma regra do ConfirmaSenha::senhaConfirmada() (o trait é das telas; aqui o serviço confere de novo).
     */
    private function senhaConfirmada(): bool
    {
        $em = session('walle.senha_confirmada_em');

        return is_numeric($em) && (int) $em >= now()->subMinutes((int) config('walle.confirmar_senha_minutos'))->getTimestamp();
    }

    private function rodar(int $clienteId, InstrucaoSql $instrucao): ResultadoQuery
    {
        $tipo = $instrucao->leitura ? 'leitura' : 'escrita';

        try {
            $conexao = $this->conector->conectar($clienteId, $this->servidor($clienteId));
        } catch (FalhaConexaoCliente $falha) {
            return ResultadoQuery::falha($falha->getMessage(), $tipo);
        } catch (Throwable $erro) {
            report($erro);

            return ResultadoQuery::falha('Não foi possível conectar no servidor do cliente agora. O detalhe ficou no log.', $tipo);
        }

        try {
            $this->prepararSessao($conexao);

            return $instrucao->leitura ? $this->ler($conexao, $instrucao->sql) : $this->escrever($conexao, $instrucao->sql);
        } catch (QueryException $erro) {
            // Sem report(): a QueryException leva o SQL com os valores e os dados da conexão.
            return ResultadoQuery::falha($this->mensagemDoMysql($erro), $tipo);
        } catch (Throwable $erro) {
            report($erro);

            return ResultadoQuery::falha('Não foi possível executar a instrução no cliente agora. O detalhe ficou no log.', $tipo);
        } finally {
            $conexao->desconectar();
        }
    }

    /**
     * Cadastro do servidor no Help Desk, ao vivo e só leitura (como o 220: cliente não deletado).
     *
     * @return array<string, mixed>
     */
    private function servidor(int $clienteId): array
    {
        $linha = DB::connection('help')->table('informacao_tecnica_dataserver as i')
            ->join('c_cliente as c', 'c.id', '=', 'i.fk_c_cliente')
            ->where('c.id', $clienteId)
            ->where('c.deletado', 'N')
            ->orderByDesc('i.id')
            ->select('i.*', 'c.cnpj')
            ->first() ?? throw FalhaConexaoCliente::semCadastroNoHelpDesk();

        return array_map(fn ($valor) => is_string($valor) ? trim($valor) : $valor, (array) $linha);
    }

    /** Banco do Sactus como padrão e limite de tempo para SELECT (servidor sem MAX_EXECUTION_TIME segue sem ele). */
    private function prepararSessao(ConexaoComCliente $conexao): void
    {
        $conexao->conexao->unprepared('USE `'.str_replace('`', '', $conexao->bancoActus).'`');

        try {
            $conexao->conexao->statement('SET SESSION MAX_EXECUTION_TIME = '.self::TEMPO_MAXIMO_MS);
        } catch (QueryException) {
            // MySQL antigo ou MariaDB: o wait_timeout do ConectorDeCliente continua valendo
        }
    }

    private function ler(ConexaoComCliente $conexao, string $sql): ResultadoQuery
    {
        $limite = max(1, (int) config('walle.ferramentas.query_limite_linhas', self::LIMITE_LINHAS));
        $linhas = [];
        $truncado = false;

        foreach ($conexao->conexao->cursor($sql) as $linha) {
            if (count($linhas) >= $limite) {
                $truncado = true;

                break;
            }

            $linhas[] = array_map($this->texto(...), (array) $linha);
        }

        $total = count($linhas);
        $mensagem = match (true) {
            $truncado => "Mostrando as primeiras {$limite} linhas; o resultado tem mais. Refine a consulta (WHERE ou LIMIT).",
            $total === 0 => 'Consulta executada: nenhuma linha.',
            $total === 1 => 'Consulta executada: 1 linha.',
            default => "Consulta executada: {$total} linhas.",
        };

        return new ResultadoQuery(true, $mensagem, array_map(strval(...), array_keys($linhas[0] ?? [])), $linhas, tipo: 'leitura', truncado: $truncado);
    }

    private function escrever(ConexaoComCliente $conexao, string $sql): ResultadoQuery
    {
        $afetadas = $conexao->conexao->affectingStatement($sql);

        return new ResultadoQuery(true, $afetadas === 1 ? 'Instrução executada: 1 linha afetada.' : "Instrução executada: {$afetadas} linhas afetadas.", afetadas: $afetadas, tipo: 'escrita');
    }

    /** Os clientes guardam ora UTF-8, ora latin1 de verdade (conexão latin1, como o 220); sem trim, para não mudar o dado. */
    private function texto(mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        $texto = (string) $valor;

        return mb_check_encoding($texto, 'UTF-8') ? $texto : mb_convert_encoding($texto, 'UTF-8', 'ISO-8859-1');
    }

    /** Erro do MySQL sem usuário@host e sem o trecho do SQL ("near '...'"), que pode ter valores. */
    private function mensagemDoMysql(QueryException $erro): string
    {
        $info = $erro->errorInfo ?? [];
        $codigo = isset($info[1]) ? (int) $info[1] : 0;
        $texto = trim((string) ($info[2] ?? ''));

        if ($texto === '') {
            return 'O MySQL do cliente recusou a instrução.';
        }

        $texto = (string) preg_replace("/'[^']*'@'[^']*'/", '[usuário]', $texto);
        $texto = (string) preg_replace("/\\s*near\\s+'.*'\\s+at\\s+line\\s+(\\d+)/s", ' (linha $1)', $texto);

        return mb_substr('O MySQL do cliente recusou a instrução'.($codigo > 0 ? " ({$codigo})" : '').': '.$this->texto($texto), 0, 400);
    }

    /** @param array<string, mixed> $detalhes */
    private function auditar(int $clienteId, array $detalhes): void
    {
        Auditoria::registrar(self::ACAO, $clienteId > 0 ? $clienteId : null, $detalhes);
    }
}
