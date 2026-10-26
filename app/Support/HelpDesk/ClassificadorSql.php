<?php

namespace App\Support\HelpDesk;

/**
 * Diz se uma instrução SQL (MySQL) é só leitura: SELECT, SHOW, DESCRIBE/DESC ou EXPLAIN.
 *
 * Na dúvida responde que não é. Comentários, espaços, parênteses e maiúsculas no começo não enganam;
 * o que está dentro de texto entre aspas não conta. Não são leitura: SELECT ... FOR UPDATE / FOR SHARE /
 * LOCK IN SHARE MODE / INTO OUTFILE / INTO DUMPFILE, duas instruções separadas por ";", comentário
 * executável do MySQL (/*! ... * /), EXPLAIN ANALYZE e EXPLAIN de uma escrita.
 */
class ClassificadorSql
{
    private const LEITURAS = ['select', 'show', 'describe', 'desc', 'explain'];

    /** Instruções que o EXPLAIN aceita e que gravam (o EXPLAIN delas fica de fora). */
    private const ESCRITAS_EXPLICAVEIS = ['insert', 'update', 'delete', 'replace'];

    public function ehLeitura(string $sql): bool
    {
        // A barra invertida dentro de aspas depende do sql_mode do servidor (NO_BACKSLASH_ESCAPES):
        // só é leitura se for leitura nas duas leituras possíveis do texto.
        foreach ([true, false] as $barraEscapa) {
            $limpo = $this->semTextoNemComentario($sql, $barraEscapa);

            if ($limpo === null || ! $this->instrucaoDeLeitura($limpo)) {
                return false;
            }
        }

        return true;
    }

    private function instrucaoDeLeitura(string $sql): bool
    {
        $sql = strtolower(trim($sql));

        // Um ";" só no fim é aceito; qualquer coisa depois dele é outra instrução
        $sql = rtrim($sql, " \t\n\r\0\x0B;");
        if ($sql === '' || str_contains($sql, ';')) {
            return false;
        }

        $sql = ltrim($sql, " \t\n\r\0\x0B(");

        if (! preg_match('/^([a-z]+)\b(.*)$/s', $sql, $partes) || ! in_array($partes[1], self::LEITURAS, true)) {
            return false;
        }

        [, $verbo, $resto] = $partes;

        if (preg_match('/\bfor\s+(update|share)\b|\block\s+in\s+share\s+mode\b|\binto\s+(outfile|dumpfile)\b/', $sql)) {
            return false;
        }

        if (in_array($verbo, ['explain', 'describe', 'desc'], true)) {
            return $this->explicacaoDeLeitura($resto);
        }

        return true;
    }

    /** EXPLAIN/DESCRIBE de uma tabela ou de um SELECT; EXPLAIN ANALYZE executa a instrução e fica de fora. */
    private function explicacaoDeLeitura(string $resto): bool
    {
        $resto = ltrim($resto);

        if (preg_match('/^analyze\b/', $resto)) {
            return false;
        }

        $resto = (string) preg_replace('/^((extended|partitions)\s+|format\s*=\s*\w+\s*)+/', '', $resto);

        return ! preg_match('/^\(*\s*('.implode('|', self::ESCRITAS_EXPLICAVEIS).')\b/', $resto);
    }

    /**
     * Tira comentários e o conteúdo dos textos entre aspas (fica só o par de aspas).
     * Devolve null quando há comentário executável (/*!) ou texto/comentário sem fim: na dúvida, não é leitura.
     */
    private function semTextoNemComentario(string $sql, bool $barraEscapa): ?string
    {
        $limpo = '';
        $tamanho = strlen($sql);

        for ($i = 0; $i < $tamanho; $i++) {
            $caractere = $sql[$i];
            $proximo = $sql[$i + 1] ?? '';

            if ($caractere === '/' && $proximo === '*') {
                if (($sql[$i + 2] ?? '') === '!') {
                    return null;
                }

                $fim = strpos($sql, '*/', $i + 2);
                if ($fim === false) {
                    return null;
                }

                $limpo .= ' ';
                $i = $fim + 1;

                continue;
            }

            // No MySQL, "--" só abre comentário se vier seguido de espaço ou controle ("1--1" é conta)
            $comentarioDeTracos = $caractere === '-' && $proximo === '-'
                && ($i + 2 >= $tamanho || ctype_space($sql[$i + 2]) || ctype_cntrl($sql[$i + 2]));

            if ($caractere === '#' || $comentarioDeTracos) {
                $fim = strpos($sql, "\n", $i);
                $limpo .= ' ';
                $i = $fim === false ? $tamanho : $fim;

                continue;
            }

            if ($caractere === "'" || $caractere === '"' || $caractere === '`') {
                $fim = $this->fimDoTexto($sql, $i, $barraEscapa && $caractere !== '`');
                if ($fim === null) {
                    return null;
                }

                $limpo .= $caractere.$caractere;
                $i = $fim;

                continue;
            }

            $limpo .= $caractere;
        }

        return $limpo;
    }

    /** Posição da aspa que fecha o texto aberto em $inicio (aspa dobrada continua o texto). */
    private function fimDoTexto(string $sql, int $inicio, bool $barraEscapa): ?int
    {
        $aspa = $sql[$inicio];
        $tamanho = strlen($sql);

        for ($i = $inicio + 1; $i < $tamanho; $i++) {
            if ($barraEscapa && $sql[$i] === '\\') {
                $i++;

                continue;
            }

            if ($sql[$i] === $aspa) {
                if (($sql[$i + 1] ?? '') === $aspa) {
                    $i++;

                    continue;
                }

                return $i;
            }
        }

        return null;
    }
}
