<?php

namespace App\Rag;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;

class IndiceConhecimento
{
    public const POR_PAGINA = 24;

    public const CATEGORIAS = ['arquitetura' => 'Arquitetura', 'bugs_resolvidos' => 'Bugs resolvidos', 'padroes' => 'Padrões', 'tentativas_falhas' => 'Tentativas que falharam'];

    public function __construct(private ?string $arquivo = null) {}

    public function conexao(): Connection
    {
        $arquivo = $this->arquivo ?? base_path('.claude/knowledge/rag.db');
        if (! is_file($arquivo)) {
            throw new RuntimeException('Índice indisponível.');
        }

        $conexao = DB::build(['driver' => 'sqlite', 'database' => $arquivo, 'options' => [PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY], 'busy_timeout' => 1000]);
        assert($conexao instanceof Connection);

        return $conexao;
    }

    /**
     * @return array{total: int, documentos: int, ultima: ?string, agentes: list<string>, encontrados: int, pagina: int, paginas: int, nos: list<array{id: int, conteudo: string, categoria: string, agente: string, arquivo: string, criado: string, x: float, y: float}>, conexoes: list<array{origem: int, destino: int, similaridade: float}>}
     */
    public function carregar(string $agente = '', string $categoria = '', string $busca = '', int $pagina = 1): array
    {
        $db = $this->conexao();
        try {
            $documentos = $db->table('documents');
            $conteudoNormalizado = 'lower(content)';
            foreach (['Á', 'À', 'Â', 'Ã', 'É', 'Ê', 'Í', 'Ó', 'Ô', 'Õ', 'Ú', 'Ü', 'Ç'] as $maiuscula) {
                $conteudoNormalizado = "replace($conteudoNormalizado, '$maiuscula', '".mb_strtolower($maiuscula)."')";
            }
            $consulta = $db->table('knowledge')
                ->when($agente !== '', fn ($q) => $q->where('agent', $agente))
                ->when($categoria !== '', fn ($q) => $q->where('category', $categoria))
                ->when($busca !== '', fn ($q) => $q->whereRaw("instr($conteudoNormalizado, ?) > 0", [mb_strtolower(mb_substr($busca, 0, 200))]));
            $encontrados = $consulta->count();
            $paginas = max(1, (int) ceil($encontrados / self::POR_PAGINA));
            $pagina = max(1, min($paginas, $pagina));
            $linhas = $consulta->orderByDesc('created_at')->orderBy('id')->offset(($pagina - 1) * self::POR_PAGINA)->limit(self::POR_PAGINA)->get();
            $nos = [];
            $vetores = [];
            foreach ($linhas as $i => $linha) {
                $angulo = 2 * M_PI * $i / max(1, count($linhas));
                $nos[] = ['id' => (int) $linha->id, 'conteudo' => (string) $linha->content, 'categoria' => (string) $linha->category, 'agente' => (string) $linha->agent, 'arquivo' => basename((string) $linha->path), 'criado' => (string) $linha->created_at, 'x' => round(400 + 320 * cos($angulo), 2), 'y' => round(230 + 170 * sin($angulo), 2)];
                $blob = (string) $linha->embedding;
                $vetores[] = strlen($blob) === 1536 ? array_values(unpack('g*', $blob) ?: []) : [];
            }
            $conexoes = [];
            foreach ($nos as $i => $origem) {
                foreach ($nos as $j => $destino) {
                    if ($j <= $i) {
                        continue;
                    }
                    $similaridade = $this->similaridade($vetores[$i], $vetores[$j]);
                    if ($similaridade >= 0.65) {
                        $conexoes[] = ['origem' => $origem['id'], 'destino' => $destino['id'], 'similaridade' => round($similaridade, 4)];
                    }
                }
            }
            usort($conexoes, fn (array $a, array $b): int => $b['similaridade'] <=> $a['similaridade'] ?: $a['origem'] <=> $b['origem'] ?: $a['destino'] <=> $b['destino']);

            return ['total' => $db->table('knowledge')->count(), 'documentos' => $documentos->count(), 'ultima' => $documentos->max('indexed_at'), 'agentes' => $db->table('knowledge')->distinct()->orderBy('agent')->pluck('agent')->all(), 'encontrados' => $encontrados, 'pagina' => $pagina, 'paginas' => $paginas, 'nos' => $nos, 'conexoes' => array_slice($conexoes, 0, 120)];
        } finally {
            $db->disconnect();
        }
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    private function similaridade(array $a, array $b): float
    {
        if (count($a) !== 384 || count($b) !== 384) {
            return 0.0;
        }
        $produto = $normaA = $normaB = 0.0;
        foreach ($a as $i => $valor) {
            if (! is_finite($valor) || ! is_finite($b[$i])) {
                return 0.0;
            }
            $produto += $valor * $b[$i];
            $normaA += $valor * $valor;
            $normaB += $b[$i] * $b[$i];
        }

        return $normaA > 0 && $normaB > 0 ? max(-1.0, min(1.0, $produto / sqrt($normaA * $normaB))) : 0.0;
    }
}
