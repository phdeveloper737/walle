<?php

namespace App\Contingencias;

use RuntimeException;

/**
 * O Sactus foi coletado, mas a leitura do Versatus no 220 falhou. O robô grava o Sactus, preserva o Versatus que já
 * estava no walle e registra a coleta como falha.
 */
class FalhaVersatusNo220 extends RuntimeException
{
    /** @param list<Contingencia> $sactus */
    public function __construct(public readonly FalhaLeituraVersatus $falha, public readonly array $sactus)
    {
        parent::__construct($falha->getMessage(), 0, $falha);
    }
}
