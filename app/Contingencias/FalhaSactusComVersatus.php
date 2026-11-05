<?php

namespace App\Contingencias;

use App\Contingencias\Cliente\FalhaConexaoCliente;
use RuntimeException;

/**
 * A conexão com o servidor do cliente (Sactus) falhou, mas o Versatus, que vem do banco local, foi lido.
 * O robô grava o Versatus e registra a coleta como falha (set.history.cont.php:299-346 independe de connect()).
 */
class FalhaSactusComVersatus extends RuntimeException
{
    /** @param list<Contingencia> $versatus */
    public function __construct(public readonly FalhaConexaoCliente $falha, public readonly array $versatus)
    {
        parent::__construct($falha->getMessage(), 0, $falha);
    }
}
