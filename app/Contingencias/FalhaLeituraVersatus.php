<?php

namespace App\Contingencias;

use RuntimeException;
use Throwable;

/** A leitura das contingências do Versatus no banco do 220 falhou (fora do ar, timeout, erro de conexão ou de consulta). */
class FalhaLeituraVersatus extends RuntimeException
{
    public const MENSAGEM = 'Não foi possível ler as contingências do Versatus no 220.';

    public function __construct(?Throwable $anterior = null)
    {
        parent::__construct(self::MENSAGEM, 0, $anterior);
    }
}
