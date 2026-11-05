<?php

namespace App\Contingencias\Cliente;

/**
 * Tira senhas de um texto técnico (mensagem de exceção, erro de socket) antes de ele ir para a tela do admin.
 */
final class TextoSemSenha
{
    /** Limite de caracteres de cada texto guardado no detalhe técnico. */
    public const LIMITE = 2000;

    /** @param list<string> $segredos senhas que não podem aparecer */
    public static function limpar(string $texto, array $segredos): string
    {
        foreach ($segredos as $segredo) {
            if ($segredo !== '') {
                $texto = str_replace($segredo, '***', $texto);
            }
        }

        return mb_substr($texto, 0, self::LIMITE);
    }
}
