<?php

namespace App\Sactus\Ssh;

use RuntimeException;

class FalhaSsh extends RuntimeException
{
    public static function handshake(): self
    {
        return new self('falha na conexao/handshake');
    }

    public static function autenticacao(): self
    {
        return new self('autenticacao recusada (usuario/senha)');
    }
}
