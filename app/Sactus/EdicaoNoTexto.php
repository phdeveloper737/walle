<?php

namespace App\Sactus;

/**
 * Aplica um pedido de edição NO TEXTO do sactus.cfg, sem tocar no servidor (cfgEditAplicarNoTexto do antigo).
 *
 * Mexe só na linha da flag: indentação, espaços em volta do "=", comentários, ordem, linhas em branco
 * e o fim de linha do resto ficam intactos. A busca ignora maiúsculas/minúsculas (para não criar
 * HABILITA_X ao lado de habilita_x), mas ao atualizar preserva a grafia que já estava no arquivo.
 */
final class EdicaoNoTexto
{
    public const REGEX_FLAG = '~^[A-Za-z_][A-Za-z0-9_.\-]{0,254}$~';

    public const MAX_VALOR = 1000;

    public static function nomeValido(string $flag): bool
    {
        return (bool) preg_match(self::REGEX_FLAG, $flag);
    }

    /** Uma linha só, sem caractere de controle (quebraria o formato chave=valor). */
    public static function valorValido(string $valor): bool
    {
        return strlen($valor) <= self::MAX_VALOR && ! preg_match('~[\x00-\x08\x0A-\x1F\x7F]~', $valor);
    }

    /** @return array{ok: bool, mudou: bool, novo: string, msg: string, erro: string} */
    public static function aplicar(string $conteudo, string $tipo, string $flag, string $valor): array
    {
        $r = ['ok' => false, 'mudou' => false, 'novo' => $conteudo, 'msg' => '', 'erro' => ''];
        $flagRe = preg_quote($flag, '~');

        // Linha de atribuição da flag ("=" ou ":", como o parse da coleta aceita). O ^[ \t]* não casa linha
        // comentada e o [^\r\n]* deixa o \r do CRLF fora da captura.
        $padrao = '~^([ \t]*)('.$flagRe.')([ \t]*)([=:])([ \t]*)([^\r\n]*)~mi';
        $ocorrencias = preg_match_all($padrao, $conteudo, $m);

        if ($ocorrencias === false) {
            return [...$r, 'erro' => 'falha ao interpretar o arquivo (limite de backtracking do preg)'];
        }

        if ($tipo === 'ADICIONAR') {
            if ($ocorrencias === 0) {
                $eol = str_contains($conteudo, "\r\n") ? "\r\n" : "\n";
                $novo = $conteudo !== '' && ! str_ends_with($conteudo, "\n") ? $conteudo.$eol : $conteudo;

                return [...$r, 'ok' => true, 'mudou' => true, 'novo' => $novo.$flag.'='.$valor.$eol, 'msg' => 'flag inexistente, incluida no fim do arquivo'];
            }

            // Já existe: troca só o valor da primeira ocorrência
            $feito = false;
            $novo = preg_replace_callback($padrao, function (array $mm) use (&$feito, $valor): string {
                if ($feito) {
                    return $mm[0];
                }

                $feito = true;

                return $mm[1].$mm[2].$mm[3].$mm[4].$mm[5].$valor;
            }, $conteudo);

            if ($novo === null) {
                return [...$r, 'erro' => 'falha ao reescrever a linha da flag'];
            }

            $msg = $novo === $conteudo ? 'a flag ja estava com esse valor, arquivo nao foi alterado' : 'valor atualizado na linha existente';
            $grafia = (string) ($m[2][0] ?? $flag);

            if (strcmp($grafia, $flag) !== 0) {
                $msg .= ' | o arquivo grava a flag como "'.$grafia.'"';
            }

            if ($ocorrencias > 1) {
                $msg .= " | ATENCAO: o arquivo tem {$ocorrencias} linhas para essa flag, apenas a primeira foi alterada";
            }

            return [...$r, 'ok' => true, 'mudou' => $novo !== $conteudo, 'novo' => $novo, 'msg' => $msg];
        }

        if ($tipo === 'REMOVER') {
            if ($ocorrencias === 0) {
                return [...$r, 'ok' => true, 'msg' => 'a flag nao existe no arquivo, nada a remover'];
            }

            $novo = preg_replace('~^[ \t]*'.$flagRe.'[ \t]*[=:][^\r\n]*(?:\r?\n|$)~mi', '', $conteudo, -1, $removidas);

            if ($novo === null) {
                return [...$r, 'erro' => 'falha ao remover a linha da flag'];
            }

            return [...$r, 'ok' => true, 'mudou' => $novo !== $conteudo, 'novo' => $novo, 'msg' => $removidas.' linha(s) removida(s)'];
        }

        return [...$r, 'erro' => 'tipo de edicao desconhecido: '.$tipo];
    }

    /**
     * As chaves do arquivo sem a flag editada, para conferir que nada mais mudou.
     *
     * @return array<string, string>
     */
    public static function chavesSemFlag(string $conteudo, string $flag): array
    {
        $saida = [];

        foreach (ArquivoCfg::ler($conteudo) as $chave => $valor) {
            if (strcasecmp((string) $chave, $flag) !== 0) {
                $saida[(string) $chave] = is_array($valor) ? (string) json_encode($valor) : (string) $valor;
            }
        }

        ksort($saida);

        return $saida;
    }
}
