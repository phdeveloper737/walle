<?php

namespace App\Support\HelpDesk;

use App\Models\Auditoria;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Log;
use Throwable;
use WeakMap;

/**
 * Trava de leitura nas conexões com o Help Desk de produção (192.168.10.254), que o walle só consulta.
 *
 * Registrada em toda conexão criada (AppServiceProvider, evento ConnectionEstablished), roda no
 * Connection::beforeExecuting(): antes de cada consulta e antes de o Laravel abrir o PDO. Uma conexão é
 * protegida quando:
 * - o host dela está em walle.help_desk.hosts_producao (config viva ou config/database.php com o mesmo nome); ou
 * - o nome dela é "help" ou está em walle.help_desk.conexoes_somente_leitura, qualquer que seja o host.
 *   A única isenção é nos testes, e só quando o banco da própria conexão é o de teste do Help Desk
 *   (walle_testes_help ou walle_testes_tNN_help), onde as fixtures precisam ser gravadas.
 *
 * Numa conexão protegida só passa leitura (ClassificadorSql); o resto fica na auditoria
 * (help_desk.escrita_barrada, sem SQL nem valores, gravada pela conexão "auditoria" para não ser desfeita
 * junto com a transação em curso) e lança EscritaNoHelpDeskDeProducao.
 * Os comandos que o conector do MySQL roda ao conectar (use, SET NAMES, sql_mode) vão direto no PDO
 * e não passam por aqui.
 */
class TravaHelpDeskProducao
{
    public const ACAO_AUDITORIA = 'help_desk.escrita_barrada';

    /** A conexão do Help Desk é sempre somente leitura, mesmo que a lista do config venha vazia. */
    private const CONEXAO_HELP = 'help';

    /**
     * Conexão onde a tentativa barrada é registrada: cópia da "mysql" numa sessão própria, para a auditoria
     * não ser desfeita junto com a transação em curso. Nunca aponta para o Help Desk.
     */
    private const CONEXAO_AUDITORIA = 'auditoria';

    /** Único banco em que, nos testes, a conexão protegida pelo nome pode gravar (as fixtures do Help Desk). */
    private const BANCO_DE_TESTE_DO_HELP_DESK = '/^walle_testes(_t[0-9]+)?_help\z/';

    /** Palavras que podem ir para a auditoria como "instrucao"; o resto vira "desconhecida". */
    private const INSTRUCOES = [
        'alter', 'analyze', 'begin', 'binlog', 'cache', 'call', 'change', 'check', 'checksum', 'clone', 'commit',
        'create', 'deallocate', 'delete', 'desc', 'describe', 'do', 'drop', 'execute', 'explain', 'flush', 'grant',
        'handler', 'help', 'import', 'insert', 'install', 'kill', 'load', 'lock', 'optimize', 'prepare', 'purge',
        'release', 'rename', 'repair', 'replace', 'reset', 'resignal', 'restart', 'revoke', 'rollback', 'savepoint',
        'select', 'set', 'show', 'shutdown', 'signal', 'start', 'table', 'truncate', 'uninstall', 'unlock',
        'update', 'use', 'values', 'with', 'xa',
    ];

    /** Evita laço quando a própria gravação da auditoria é barrada. */
    private static bool $registrando = false;

    /** @var WeakMap<Connection, true> */
    private WeakMap $protegidas;

    private readonly bool $emTestes;

    /** @param  bool|null  $emTestes  null: descobre pelo ambiente (app()->runningUnitTests()) */
    public function __construct(private readonly ClassificadorSql $classificador, ?bool $emTestes = null)
    {
        $this->protegidas = new WeakMap;
        $this->emTestes = $emTestes ?? app()->runningUnitTests();
    }

    public function proteger(Connection $conexao): void
    {
        if (isset($this->protegidas[$conexao])) {
            return;
        }

        $this->protegidas[$conexao] = true;

        $conexao->beforeExecuting(function (string $sql, array $bindings, Connection $conexao): void {
            $destino = $this->destinoProtegido($conexao);

            if ($destino !== null) {
                $this->barrarEscrita($sql, $destino, self::nomeDaConexao($conexao));
            }
        });
    }

    /**
     * O destino protegido desta conexão (host de produção, host da conexão somente leitura ou, se o host
     * não for texto, o nome dela), ou null se ela pode gravar.
     */
    public function destinoProtegido(Connection $conexao): ?string
    {
        $nome = self::nomeDaConexao($conexao);

        // A config viva da conexão (perde os blocos read/write) e a do config/database.php com o mesmo nome
        $host = self::hostDeProducao($conexao->getConfig())
            ?? ($nome !== null ? self::hostDeProducao((array) config('database.connections.'.$nome, [])) : null);

        if ($host !== null) {
            return $host;
        }

        if ($nome !== null && $this->somenteLeituraPeloNome($nome, $conexao->getConfig('database'))) {
            return self::hostOuNome($conexao->getConfig('host'), $nome);
        }

        return null;
    }

    /**
     * Registra na auditoria uma tentativa de escrita barrada. Nunca recebe SQL nem valores: só o nome
     * da conexão, o host e a instrução (uma palavra conhecida, ex.: "insert" ou "eloquent_save").
     * Grava sempre pela conexão "auditoria" (sessão própria no banco do walle): o registro fica mesmo que a
     * transação em curso na conexão padrão seja desfeita pela exceção.
     * Se a gravação falhar (inclusive barrada por esta mesma trava), fica um Log::warning no lugar.
     */
    public static function registrarTentativa(string $conexao, string $host, string $instrucao): void
    {
        if (self::$registrando) {
            return;
        }

        self::$registrando = true;

        $detalhes = [
            'conexao' => $conexao,
            'host' => $host,
            'instrucao' => $instrucao,
            'contexto' => app()->runningInConsole() ? 'console' : 'web',
        ];

        try {
            Auditoria::registrar(self::ACAO_AUDITORIA, null, $detalhes, self::CONEXAO_AUDITORIA);
        } catch (Throwable) {
            Log::warning('Escrita no Help Desk barrada; a auditoria não pôde ser gravada.', $detalhes);
        } finally {
            self::$registrando = false;
        }
    }

    /**
     * O host da configuração que está na lista de produção, ou null se nenhum estiver.
     * Considera "host" (texto ou lista) e os blocos "read"/"write"; compara sem maiúsculas nem espaços.
     *
     * @param  array<string, mixed>  $config
     */
    public static function hostDeProducao(array $config): ?string
    {
        $producao = array_map(self::normalizar(...), (array) config('walle.help_desk.hosts_producao', []));

        $hosts = (array) ($config['host'] ?? []);
        foreach (['read', 'write'] as $tipo) {
            if (is_array($config[$tipo] ?? null)) {
                $hosts = array_merge($hosts, (array) ($config[$tipo]['host'] ?? []));
            }
        }

        foreach ($hosts as $host) {
            if (is_string($host) && in_array(self::normalizar($host), $producao, true)) {
                return trim($host);
            }
        }

        return null;
    }

    /** A primeira palavra da instrução (depois de espaços, parênteses e comentários), se for conhecida. */
    public static function instrucao(string $sql): string
    {
        $semComentario = (string) preg_replace('/^(?:\s+|\(|\/\*(?!!).*?\*\/|(?:#|--(?=\s))[^\n]*(?:\n|$))*/s', '', $sql);

        if (preg_match('/^([a-z]{1,20})\b/i', $semComentario, $partes) && in_array(strtolower($partes[1]), self::INSTRUCOES, true)) {
            return strtolower($partes[1]);
        }

        return 'desconhecida';
    }

    private function barrarEscrita(string $sql, string $destino, ?string $conexao): void
    {
        if ($this->classificador->ehLeitura($sql)) {
            return;
        }

        self::registrarTentativa($conexao ?? 'desconhecida', $destino, self::instrucao($sql));

        throw new EscritaNoHelpDeskDeProducao($destino);
    }

    /**
     * Se a conexão é somente leitura pelo nome ("help" ou da lista walle.help_desk.conexoes_somente_leitura).
     * Nos testes, a conexão da lista só fica livre quando o banco dela é o de teste do Help Desk
     * (walle_testes_help ou walle_testes_tNN_help, comparação exata); fora dos testes nunca há isenção.
     *
     * @param  mixed  $banco  banco da própria conexão verificada (config viva)
     */
    private function somenteLeituraPeloNome(string $nome, mixed $banco): bool
    {
        $configurada = config('walle.help_desk.conexoes_somente_leitura', [self::CONEXAO_HELP]);
        $lista = [self::CONEXAO_HELP, ...(is_string($configurada) ? explode(',', $configurada) : (array) $configurada)];

        if (! in_array(self::normalizar($nome), array_map(self::normalizar(...), $lista), true)) {
            return false;
        }

        if ($this->emTestes && is_string($banco) && preg_match(self::BANCO_DE_TESTE_DO_HELP_DESK, $banco) === 1) {
            return false;
        }

        return true;
    }

    /** Nome da conexão como está no config/database.php (sem o "::read", "::write" ou "::direct"). */
    private static function nomeDaConexao(Connection $conexao): ?string
    {
        $nome = $conexao->getConfig('name');

        if (! is_string($nome) || trim($nome) === '') {
            return null;
        }

        return explode('::', trim($nome), 2)[0];
    }

    private static function hostOuNome(mixed $host, string $nome): string
    {
        return is_string($host) && trim($host) !== '' ? trim($host) : $nome;
    }

    private static function normalizar(mixed $host): string
    {
        return strtolower(trim(is_scalar($host) ? (string) $host : ''));
    }
}
