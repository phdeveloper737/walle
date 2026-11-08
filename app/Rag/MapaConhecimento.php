<?php

namespace App\Rag;

/**
 * Calcula o mapa do cérebro do RAG: ligações por similaridade cosseno, grupos de assunto,
 * posições por layout de força (memórias parecidas ficam perto) e a árvore de ramificações de uma memória.
 * Tudo determinístico: os mesmos vetores geram sempre o mesmo desenho.
 */
class MapaConhecimento
{
    public const LARGURA = 1200;

    public const ALTURA = 760;

    /** Ligações mais fortes de cada memória que entram no mapa (o resto poluiria o desenho). */
    public const LIGACOES_POR_NO = 5;

    /** Vizinhos guardados por memória para o painel e para as ramificações. */
    public const VIZINHOS_GUARDADOS = 8;

    /** Grupo com menos memórias que isso não ganha contorno no mapa. */
    public const MINIMO_DO_GRUPO = 3;

    private const ITERACOES = 140;

    private const MARGEM = 48;

    /**
     * @param  list<array{id: int, agente: string, categoria: string, vetor: list<float>}>  $memorias
     * @return array{
     *     nos: list<array{id: int, x: float, y: float, grau: int, grupo: int}>,
     *     conexoes: list<array{origem: int, destino: int, similaridade: float}>,
     *     grupos: list<array{id: int, x: float, y: float, raio: float, total: int, agente: string, categoria: string}>,
     *     vizinhos: array<int, list<array{id: int, similaridade: float}>>
     * }
     */
    public function montar(array $memorias, float $limiar): array
    {
        $ids = array_map(fn (array $memoria): int => $memoria['id'], $memorias);
        $unitarios = array_map(fn (array $memoria): array => self::normalizar($memoria['vetor']), $memorias);
        $quantidade = count($memorias);

        /** @var array<int, array<int, float>> $candidatos índice => [índice vizinho => similaridade] */
        $candidatos = array_fill(0, $quantidade, []);
        for ($i = 0; $i < $quantidade; $i++) {
            for ($j = $i + 1; $j < $quantidade; $j++) {
                $similaridade = self::produto($unitarios[$i], $unitarios[$j]);
                if ($similaridade >= $limiar) {
                    $candidatos[$i][$j] = $similaridade;
                    $candidatos[$j][$i] = $similaridade;
                }
            }
        }

        $vizinhos = [];
        $arestas = [];
        foreach ($candidatos as $i => $lista) {
            uksort($lista, fn (int $a, int $b): int => $lista[$b] <=> $lista[$a] ?: $ids[$a] <=> $ids[$b]);
            $vizinhos[$ids[$i]] = array_map(
                fn (int $j): array => ['id' => $ids[$j], 'similaridade' => round($lista[$j], 4)],
                array_slice(array_keys($lista), 0, self::VIZINHOS_GUARDADOS),
            );
            foreach (array_slice(array_keys($lista), 0, self::LIGACOES_POR_NO) as $j) {
                $arestas[min($i, $j).'-'.max($i, $j)] = [min($i, $j), max($i, $j), $lista[$j]];
            }
        }
        $arestas = array_values($arestas);
        usort($arestas, fn (array $a, array $b): int => $b[2] <=> $a[2] ?: $ids[$a[0]] <=> $ids[$b[0]] ?: $ids[$a[1]] <=> $ids[$b[1]]);

        $grupoDe = $this->agrupar($quantidade, $arestas);
        $posicoes = $this->posicionar($quantidade, $arestas, $grupoDe);

        $grau = array_fill(0, $quantidade, 0);
        foreach ($arestas as [$a, $b]) {
            $grau[$a]++;
            $grau[$b]++;
        }

        $nos = [];
        foreach ($ids as $i => $id) {
            $nos[] = ['id' => $id, 'x' => round($posicoes[$i][0], 1), 'y' => round($posicoes[$i][1], 1), 'grau' => $grau[$i], 'grupo' => $grupoDe[$i]];
        }

        return [
            'nos' => $nos,
            'conexoes' => array_map(fn (array $aresta): array => ['origem' => $ids[$aresta[0]], 'destino' => $ids[$aresta[1]], 'similaridade' => round($aresta[2], 4)], $arestas),
            'grupos' => $this->descreverGrupos($memorias, $posicoes, $grupoDe),
            'vizinhos' => $vizinhos,
        ];
    }

    /**
     * Árvore radial a partir de uma memória: as ligações diretas no primeiro anel e,
     * para cada uma, as ligações dela que ainda não estão na árvore, no segundo anel.
     *
     * @param  array<int, list<array{id: int, similaridade: float}>>  $vizinhos
     * @return list<array{id: int, nivel: int, pai: ?int, similaridade: ?float, x: float, y: float}>
     */
    public function ramificacoes(array $vizinhos, int $raiz, int $primeiroNivel = 8, int $segundoNivel = 3): array
    {
        if (! array_key_exists($raiz, $vizinhos)) {
            return [];
        }

        [$centroX, $centroY] = [450.0, 310.0];
        $arvore = [['id' => $raiz, 'nivel' => 0, 'pai' => null, 'similaridade' => null, 'x' => $centroX, 'y' => $centroY]];
        $usados = [$raiz => true];
        $diretos = array_slice($vizinhos[$raiz], 0, $primeiroNivel);
        foreach ($diretos as $vizinho) {
            $usados[$vizinho['id']] = true;
        }

        $fatia = 2 * M_PI / max(1, count($diretos));
        foreach ($diretos as $posicao => $direto) {
            $angulo = -M_PI / 2 + $posicao * $fatia;
            $arvore[] = ['id' => $direto['id'], 'nivel' => 1, 'pai' => $raiz, 'similaridade' => $direto['similaridade'], 'x' => round($centroX + 155 * cos($angulo), 1), 'y' => round($centroY + 155 * sin($angulo), 1)];

            $netos = [];
            foreach ($vizinhos[$direto['id']] ?? [] as $neto) {
                if (count($netos) === $segundoNivel) {
                    break;
                }
                if (! isset($usados[$neto['id']])) {
                    $usados[$neto['id']] = true;
                    $netos[] = $neto;
                }
            }
            $abertura = $fatia * 0.8;
            foreach ($netos as $ordem => $neto) {
                $anguloNeto = $angulo + (count($netos) === 1 ? 0 : -$abertura / 2 + $abertura * $ordem / (count($netos) - 1));
                $arvore[] = ['id' => $neto['id'], 'nivel' => 2, 'pai' => $direto['id'], 'similaridade' => $neto['similaridade'], 'x' => round($centroX + 275 * cos($anguloNeto), 1), 'y' => round($centroY + 275 * sin($anguloNeto), 1)];
            }
        }

        return $arvore;
    }

    /**
     * Grupos de assunto por propagação de rótulos ponderada pela similaridade (determinística).
     *
     * @param  list<array{0: int, 1: int, 2: float}>  $arestas
     * @return list<int> índice => id do grupo (1, 2, 3... do maior para o menor)
     */
    private function agrupar(int $quantidade, array $arestas): array
    {
        $adjacencia = array_fill(0, $quantidade, []);
        foreach ($arestas as [$a, $b, $peso]) {
            $adjacencia[$a][$b] = $peso;
            $adjacencia[$b][$a] = $peso;
        }

        $rotulo = range(0, max(0, $quantidade - 1));
        for ($volta = 0; $volta < 12; $volta++) {
            $mudou = false;
            for ($i = 0; $i < $quantidade; $i++) {
                if ($adjacencia[$i] === []) {
                    continue;
                }
                $pesos = [];
                foreach ($adjacencia[$i] as $j => $peso) {
                    $pesos[$rotulo[$j]] = ($pesos[$rotulo[$j]] ?? 0) + $peso;
                }
                uksort($pesos, fn (int $a, int $b): int => $pesos[$b] <=> $pesos[$a] ?: $a <=> $b);
                $melhor = array_key_first($pesos);
                if ($melhor !== $rotulo[$i] && $pesos[$melhor] > ($pesos[$rotulo[$i]] ?? 0)) {
                    $rotulo[$i] = $melhor;
                    $mudou = true;
                }
            }
            if (! $mudou) {
                break;
            }
        }

        $tamanhos = array_count_values(array_slice($rotulo, 0, $quantidade));
        uksort($tamanhos, fn (int $a, int $b): int => $tamanhos[$b] <=> $tamanhos[$a] ?: $a <=> $b);
        $numero = array_flip(array_keys($tamanhos));

        return array_map(fn (int $r): int => $numero[$r] + 1, array_slice($rotulo, 0, $quantidade));
    }

    /**
     * Layout de força (Fruchterman-Reingold): todos se repelem, as ligações atraem pela similaridade
     * e cada memória é puxada de leve para o centro do seu grupo. Posição inicial fixa por grupo.
     *
     * @param  list<array{0: int, 1: int, 2: float}>  $arestas
     * @param  list<int>  $grupoDe
     * @return list<array{0: float, 1: float}>
     */
    private function posicionar(int $quantidade, array $arestas, array $grupoDe): array
    {
        if ($quantidade === 0) {
            return [];
        }

        $largura = self::LARGURA - 2 * self::MARGEM;
        $altura = self::ALTURA - 2 * self::MARGEM;
        $ideal = 0.9 * sqrt($largura * $altura / $quantidade);
        $totalGrupos = max($grupoDe);

        $x = [];
        $y = [];
        $ordemNoGrupo = [];
        foreach ($grupoDe as $i => $grupo) {
            $anguloGrupo = 2 * M_PI * ($grupo - 1) / max(1, $totalGrupos);
            $raioGrupo = $totalGrupos === 1 ? 0 : 0.32 * min($largura, $altura);
            $ordem = $ordemNoGrupo[$grupo] = ($ordemNoGrupo[$grupo] ?? -1) + 1;
            $espiral = 9 * sqrt($ordem + 1);
            $x[$i] = $raioGrupo * cos($anguloGrupo) + $espiral * cos($ordem * 2.399963);
            $y[$i] = $raioGrupo * sin($anguloGrupo) + $espiral * sin($ordem * 2.399963);
        }

        $temperatura = $largura / 8;
        for ($volta = 0; $volta < self::ITERACOES; $volta++) {
            $dx = array_fill(0, $quantidade, 0.0);
            $dy = array_fill(0, $quantidade, 0.0);

            for ($i = 0; $i < $quantidade; $i++) {
                for ($j = $i + 1; $j < $quantidade; $j++) {
                    $vx = $x[$i] - $x[$j];
                    $vy = $y[$i] - $y[$j];
                    $distancia2 = max(0.01, $vx * $vx + $vy * $vy);
                    $forca = $ideal * $ideal / $distancia2;
                    $dx[$i] += $vx * $forca;
                    $dy[$i] += $vy * $forca;
                    $dx[$j] -= $vx * $forca;
                    $dy[$j] -= $vy * $forca;
                }
            }

            foreach ($arestas as [$a, $b, $peso]) {
                $vx = $x[$a] - $x[$b];
                $vy = $y[$a] - $y[$b];
                $distancia = max(0.1, sqrt($vx * $vx + $vy * $vy));
                $forca = $distancia * $peso * $peso / $ideal;
                $dx[$a] -= $vx * $forca;
                $dy[$a] -= $vy * $forca;
                $dx[$b] += $vx * $forca;
                $dy[$b] += $vy * $forca;
            }

            for ($i = 0; $i < $quantidade; $i++) {
                $dx[$i] -= 0.02 * $x[$i] * $ideal / 10;
                $dy[$i] -= 0.02 * $y[$i] * $ideal / 10;
                $deslocamento = sqrt($dx[$i] * $dx[$i] + $dy[$i] * $dy[$i]);
                if ($deslocamento > 0) {
                    $passo = min($deslocamento, $temperatura) / $deslocamento;
                    $x[$i] += $dx[$i] * $passo;
                    $y[$i] += $dy[$i] * $passo;
                }
            }

            $temperatura = max(1.0, $temperatura * 0.965);
        }

        return $this->enquadrar($x, $y);
    }

    /**
     * Estica as posições para ocupar a área útil sem distorcer a proporção.
     *
     * @param  array<int, float>  $x
     * @param  array<int, float>  $y
     * @return list<array{0: float, 1: float}>
     */
    private function enquadrar(array $x, array $y): array
    {
        [$minX, $maxX, $minY, $maxY] = [min($x), max($x), min($y), max($y)];
        $escala = min(
            (self::LARGURA - 2 * self::MARGEM) / max(1.0, $maxX - $minX),
            (self::ALTURA - 2 * self::MARGEM) / max(1.0, $maxY - $minY),
        );
        $sobraX = (self::LARGURA - ($maxX - $minX) * $escala) / 2;
        $sobraY = (self::ALTURA - ($maxY - $minY) * $escala) / 2;

        $posicoes = [];
        foreach ($x as $i => $valor) {
            $posicoes[] = [$sobraX + ($valor - $minX) * $escala, $sobraY + ($y[$i] - $minY) * $escala];
        }

        return $posicoes;
    }

    /**
     * @param  list<array{id: int, agente: string, categoria: string, vetor: list<float>}>  $memorias
     * @param  list<array{0: float, 1: float}>  $posicoes
     * @param  list<int>  $grupoDe
     * @return list<array{id: int, x: float, y: float, raio: float, total: int, agente: string, categoria: string}>
     */
    private function descreverGrupos(array $memorias, array $posicoes, array $grupoDe): array
    {
        $membros = [];
        foreach ($grupoDe as $i => $grupo) {
            $membros[$grupo][] = $i;
        }

        $grupos = [];
        foreach ($membros as $grupo => $indices) {
            if (count($indices) < self::MINIMO_DO_GRUPO) {
                continue;
            }
            $cx = array_sum(array_map(fn (int $i): float => $posicoes[$i][0], $indices)) / count($indices);
            $cy = array_sum(array_map(fn (int $i): float => $posicoes[$i][1], $indices)) / count($indices);
            $raio = max(array_map(fn (int $i): float => hypot($posicoes[$i][0] - $cx, $posicoes[$i][1] - $cy), $indices)) + 18;
            $grupos[] = [
                'id' => $grupo,
                'x' => round($cx, 1),
                'y' => round($cy, 1),
                'raio' => round($raio, 1),
                'total' => count($indices),
                'agente' => self::maisFrequente(array_map(fn (int $i): string => $memorias[$i]['agente'], $indices)),
                'categoria' => self::maisFrequente(array_map(fn (int $i): string => $memorias[$i]['categoria'], $indices)),
            ];
        }
        usort($grupos, fn (array $a, array $b): int => $a['id'] <=> $b['id']);

        return $grupos;
    }

    /** @param list<string> $valores */
    private static function maisFrequente(array $valores): string
    {
        $contagem = array_count_values($valores);
        uksort($contagem, fn (string $a, string $b): int => $contagem[$b] <=> $contagem[$a] ?: strcmp($a, $b));

        return (string) array_key_first($contagem);
    }

    /**
     * Vetor de tamanho 1 para o cosseno virar produto escalar; vetor inválido vira lista vazia (sem ligações).
     *
     * @param  list<float>  $vetor
     * @return list<float>
     */
    private static function normalizar(array $vetor): array
    {
        if (count($vetor) !== 384) {
            return [];
        }
        $norma = 0.0;
        foreach ($vetor as $valor) {
            if (! is_finite($valor)) {
                return [];
            }
            $norma += $valor * $valor;
        }
        if ($norma <= 0) {
            return [];
        }
        $norma = sqrt($norma);

        return array_map(fn (float $valor): float => $valor / $norma, $vetor);
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    private static function produto(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }
        $soma = 0.0;
        foreach ($a as $i => $valor) {
            $soma += $valor * $b[$i];
        }

        return max(-1.0, min(1.0, $soma));
    }
}
