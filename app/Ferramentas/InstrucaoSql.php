<?php

namespace App\Ferramentas;

use App\Support\HelpDesk\ClassificadorSql;

/**
 * Uma instrução SQL digitada por admin na tela de Ferramentas, já conferida.
 *
 * Só aceita UMA instrução: ";" fora de texto ou comentário só no fim. Na dúvida recusa: texto ou comentário sem fim,
 * comentário executável do MySQL (/*! ... * /) e ";" no meio, em qualquer das duas leituras da barra invertida
 * (depende do sql_mode NO_BACKSLASH_ESCAPES do servidor). A leitura/escrita segue o ClassificadorSql (o mesmo da trava
 * do Help Desk). A versão mascarada (para a auditoria) troca textos e números por "?" e tira os comentários.
 */
final readonly class InstrucaoSql
{
    public const LIMITE_MASCARADA = 500;

    private function __construct(
        public string $sql,
        public bool $leitura,
        public string $mascarada,
    ) {}

    /** @return self|null null quando vazia, com mais de uma instrução ou impossível de ler com segurança */
    public static function analisar(string $sql): ?self
    {
        $mascarada = null;

        foreach ([true, false] as $barraEscapa) {
            $limpa = self::semTextoNemComentario($sql, $barraEscapa);

            if ($limpa === null) {
                return null;
            }

            $estrutura = rtrim($limpa, " \t\n\r\0\x0B;");

            if (trim($estrutura) === '' || str_contains($estrutura, ';')) {
                return null;
            }

            $mascarada ??= $estrutura;
        }

        return new self(
            rtrim($sql, " \t\n\r\0\x0B;"),
            (new ClassificadorSql)->ehLeitura($sql),
            self::mascarar((string) $mascarada),
        );
    }

    private static function mascarar(string $estrutura): string
    {
        $semNumeros = (string) preg_replace(
            '/(?<![\w$])(?:0x[0-9a-f]+|\d+(?:\.\d*)?(?:e[+-]?\d+)?|\.\d+(?:e[+-]?\d+)?)(?![\w$])/i',
            '?',
            $estrutura,
        );

        return mb_substr(trim((string) preg_replace('/\s+/', ' ', $semNumeros)), 0, self::LIMITE_MASCARADA);
    }

    /**
     * Troca cada texto entre aspas simples ou duplas por "?" e cada comentário por espaço; nomes entre crases ficam.
     * Devolve null quando há comentário executável (/*!) ou texto/comentário sem fim.
     */
    private static function semTextoNemComentario(string $sql, bool $barraEscapa): ?string
    {
        $limpo = '';
        $tamanho = strlen($sql);

        for ($i = 0; $i < $tamanho; $i++) {
            $caractere = $sql[$i];
            $proximo = $sql[$i + 1] ?? '';

            if ($caractere === '/' && $proximo === '*') {
                $fim = strpos($sql, '*/', $i + 2);

                if (($sql[$i + 2] ?? '') === '!' || $fim === false) {
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
                $fim = self::fimDoTexto($sql, $i, $barraEscapa && $caractere !== '`');

                if ($fim === null) {
                    return null;
                }

                $limpo .= $caractere === '`' ? substr($sql, $i, $fim - $i + 1) : '?';
                $i = $fim;

                continue;
            }

            $limpo .= $caractere;
        }

        return $limpo;
    }

    /** Posição da aspa que fecha o texto aberto em $inicio (aspa dobrada continua o texto). */
    private static function fimDoTexto(string $sql, int $inicio, bool $barraEscapa): ?int
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
