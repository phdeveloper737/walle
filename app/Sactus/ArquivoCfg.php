<?php

namespace App\Sactus;

/**
 * O /etc/sactus.cfg: leitura igual ao parseCfg() do antigo e máscara dos segredos.
 * O antigo mandava as senhas para o navegador e só escondia no visual; o walle mascara no servidor.
 */
final class ArquivoCfg
{
    public const SENSIVEL = '~(senha|password|passwd|secret|token)~i';

    /**
     * JSON puro ou chave=valor / chave: valor, com [seções] e comentários (# e ;).
     *
     * @return array<string, mixed>
     */
    public static function ler(string $bruto): array
    {
        $texto = (string) preg_replace('~^\xEF\xBB\xBF~', '', trim($bruto));

        if ($texto === '') {
            return [];
        }

        $json = json_decode($texto, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
            return $json;
        }

        $saida = [];
        $secao = '';

        foreach (preg_split('~\r\n|\n|\r~', $texto) ?: [] as $linha) {
            $l = trim($linha);

            if ($l === '' || $l[0] === '#' || $l[0] === ';') {
                continue;
            }

            if (preg_match('~^\[(.+)\]$~', $l, $m)) {
                $secao = trim($m[1]);
                $saida[$secao] ??= [];

                continue;
            }

            if (preg_match('~^([^=:]+)\s*[=:]\s*(.*)$~', $l, $m)) {
                $chave = trim($m[1]);
                $valor = trim(trim($m[2]), "\"'");

                if ($secao !== '') {
                    $saida[$secao][$chave] = $valor;
                } else {
                    $saida[$chave] = $valor;
                }
            }
        }

        return $saida;
    }

    public static function sensivel(string $chave): bool
    {
        return (bool) preg_match(self::SENSIVEL, $chave);
    }

    /** O arquivo com o valor das chaves sensíveis trocado por ••••••, o resto intacto. */
    public static function mascarar(string $conteudo): string
    {
        return (string) preg_replace_callback(
            '~^([ \t]*)([^=:\r\n#;]+?)([ \t]*[=:][ \t]*)([^\r\n]*)~m',
            fn (array $m): string => self::sensivel($m[2]) && $m[4] !== '' ? $m[1].$m[2].$m[3].'••••••' : $m[0],
            $conteudo,
        );
    }
}
