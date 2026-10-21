<?php

namespace App\Support;

class Texto
{
    /**
     * Garante UTF-8 válido. Os servidores dos clientes guardam texto ora em UTF-8 dentro de coluna latin1,
     * ora em latin1 de verdade: lendo com conexão latin1, o primeiro já vem certo e o segundo é convertido aqui.
     */
    public static function utf8(mixed $valor): string
    {
        $texto = trim((string) $valor);

        if ($texto === '' || mb_check_encoding($texto, 'UTF-8')) {
            return $texto;
        }

        return mb_convert_encoding($texto, 'UTF-8', 'ISO-8859-1');
    }
}
