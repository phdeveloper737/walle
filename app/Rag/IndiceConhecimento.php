<?php

namespace App\Rag;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Pdo\Sqlite;
use RuntimeException;

class IndiceConhecimento
{
    public const POR_PAGINA = 24;

    /** Memórias mais recentes do filtro que entram no mapa. */
    public const LIMITE_DO_MAPA = 300;

    /** Similaridades mínimas (em %) que a tela oferece para as ligações. */
    public const LIMIARES = [65, 75, 85];

    /** O mapa só muda quando o índice é reindexado; a chave do cache leva a última indexação. */
    private const SEGUNDOS_DO_MAPA = 86400;

    public const CATEGORIAS = ['arquitetura' => 'Arquitetura', 'bugs_resolvidos' => 'Bugs resolvidos', 'padroes' => 'Padrões', 'tentativas_falhas' => 'Tentativas que falharam'];

    public function __construct(private ?string $arquivo = null) {}

    public function conexao(): Connection
    {
        $arquivo = $this->caminho();
        if (! is_file($arquivo)) {
            throw new RuntimeException('Índice indisponível.');
        }

        $conexao = DB::build(['driver' => 'sqlite', 'database' => $arquivo, 'options' => [Sqlite::ATTR_OPEN_FLAGS => Sqlite::OPEN_READONLY], 'busy_timeout' => 1000]);
        assert($conexao instanceof Connection);

        return $conexao;
    }

    /**
     * Resumo do índice e a página de memórias da lista.
     *
     * @return array{total: int, documentos: int, ultima: ?string, agentes: list<string>, encontrados: int, pagina: int, paginas: int, nos: list<array{id: int, conteudo: string, categoria: string, agente: string, arquivo: string, criado: string}>}
     */
    public function carregar(string $agente = '', string $categoria = '', string $busca = '', int $pagina = 1): array
    {
        $db = $this->conexao();
        try {
            $documentos = $db->table('documents');
            $consulta = $this->filtrar($db, $agente, $categoria, $busca);
            $encontrados = $consulta->count();
            $paginas = max(1, (int) ceil($encontrados / self::POR_PAGINA));
            $pagina = max(1, min($paginas, $pagina));
            $linhas = $consulta->orderByDesc('created_at')->orderBy('id')->offset(($pagina - 1) * self::POR_PAGINA)->limit(self::POR_PAGINA)
                ->get(['id', 'content', 'category', 'agent', 'path', 'created_at']);

            return [
                'total' => $db->table('knowledge')->count(),
                'documentos' => $documentos->count(),
                'ultima' => $documentos->max('indexed_at'),
                'agentes' => $db->table('knowledge')->distinct()->orderBy('agent')->pluck('agent')->all(),
                'encontrados' => $encontrados,
                'pagina' => $pagina,
                'paginas' => $paginas,
                'nos' => array_values($linhas->map(fn (object $linha): array => self::memoriaDaLinha($linha))->all()),
            ];
        } finally {
            $db->disconnect();
        }
    }

    /**
     * Mapa das memórias mais recentes do filtro (até LIMITE_DO_MAPA), com posições, grupos e ligações.
     * Guardado em cache: calcular as similaridades e o layout de 300 memórias leva alguns segundos.
     *
     * @return array{
     *     encontrados: int,
     *     limiar: int,
     *     nos: list<array{id: int, x: float, y: float, grau: int, grupo: int, agente: string, categoria: string, resumo: string}>,
     *     conexoes: list<array{origem: int, destino: int, similaridade: float}>,
     *     grupos: list<array{id: int, x: float, y: float, raio: float, total: int, agente: string, categoria: string}>,
     *     vizinhos: array<int, list<array{id: int, similaridade: float}>>
     * }
     */
    public function mapa(string $agente = '', string $categoria = '', string $busca = '', int $limiar = 65): array
    {
        $limiar = in_array($limiar, self::LIMIARES, true) ? $limiar : self::LIMIARES[0];
        $db = $this->conexao();
        try {
            $ultima = (string) $db->table('documents')->max('indexed_at');
            $total = $db->table('knowledge')->count();
            $chave = 'rag.mapa.'.md5((string) json_encode([$this->caminho(), $ultima, $total, $agente, $categoria, mb_substr($busca, 0, 200), $limiar]));

            return Cache::remember($chave, self::SEGUNDOS_DO_MAPA, function () use ($db, $agente, $categoria, $busca, $limiar): array {
                $consulta = $this->filtrar($db, $agente, $categoria, $busca);
                $encontrados = $consulta->count();
                $linhas = $consulta->orderByDesc('created_at')->orderBy('id')->limit(self::LIMITE_DO_MAPA)
                    ->get(['id', 'content', 'category', 'agent', 'embedding']);

                $memorias = [];
                $detalhes = [];
                foreach ($linhas as $linha) {
                    $blob = (string) $linha->embedding;
                    $memorias[] = [
                        'id' => (int) $linha->id,
                        'agente' => (string) $linha->agent,
                        'categoria' => (string) $linha->category,
                        'vetor' => strlen($blob) === 1536 ? array_values(unpack('g*', $blob) ?: []) : [],
                    ];
                    $detalhes[(int) $linha->id] = ['agente' => (string) $linha->agent, 'categoria' => (string) $linha->category, 'resumo' => self::resumir((string) $linha->content)];
                }

                $mapa = app(MapaConhecimento::class)->montar($memorias, $limiar / 100);
                $mapa['nos'] = array_map(fn (array $no): array => $no + $detalhes[$no['id']], $mapa['nos']);

                return ['encontrados' => $encontrados, 'limiar' => $limiar] + $mapa;
            });
        } finally {
            $db->disconnect();
        }
    }

    /**
     * Uma memória pelo id, para o painel de leitura (pode estar fora da página da lista).
     *
     * @return array{id: int, conteudo: string, categoria: string, agente: string, arquivo: string, criado: string}|null
     */
    public function memoria(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $db = $this->conexao();
        try {
            $linha = $db->table('knowledge')->where('id', $id)->first(['id', 'content', 'category', 'agent', 'path', 'created_at']);

            return $linha === null ? null : self::memoriaDaLinha($linha);
        } finally {
            $db->disconnect();
        }
    }

    private function caminho(): string
    {
        return $this->arquivo ?? base_path('.claude/knowledge/rag.db');
    }

    private function filtrar(Connection $db, string $agente, string $categoria, string $busca): Builder
    {
        $conteudoNormalizado = 'lower(content)';
        foreach (['Á', 'À', 'Â', 'Ã', 'É', 'Ê', 'Í', 'Ó', 'Ô', 'Õ', 'Ú', 'Ü', 'Ç'] as $maiuscula) {
            $conteudoNormalizado = "replace($conteudoNormalizado, '$maiuscula', '".mb_strtolower($maiuscula)."')";
        }

        return $db->table('knowledge')
            ->when($agente !== '', fn (Builder $q) => $q->where('agent', $agente))
            ->when($categoria !== '', fn (Builder $q) => $q->where('category', $categoria))
            ->when($busca !== '', fn (Builder $q) => $q->whereRaw("instr($conteudoNormalizado, ?) > 0", [mb_strtolower(mb_substr($busca, 0, 200))]));
    }

    /** @return array{id: int, conteudo: string, categoria: string, agente: string, arquivo: string, criado: string} */
    private static function memoriaDaLinha(object $linha): array
    {
        return [
            'id' => (int) $linha->id,
            'conteudo' => (string) $linha->content,
            'categoria' => (string) $linha->category,
            'agente' => (string) $linha->agent,
            'arquivo' => basename((string) $linha->path),
            'criado' => (string) $linha->created_at,
        ];
    }

    /** Primeira linha útil da memória, curta, para a dica do mapa. */
    private static function resumir(string $conteudo): string
    {
        $texto = trim((string) preg_replace('/\s+/u', ' ', strip_tags($conteudo)));

        return mb_strlen($texto) > 140 ? mb_substr($texto, 0, 139).'…' : $texto;
    }
}
