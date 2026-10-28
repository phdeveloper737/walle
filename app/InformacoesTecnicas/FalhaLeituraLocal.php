<?php

namespace App\InformacoesTecnicas;

use RuntimeException;

/**
 * A leitura no banco local falhou (na importação, antes de qualquer gravação).
 * Propositalmente sem a exceção original: ela carrega o SQL, que não pode ir para o log nem para a tela.
 */
class FalhaLeituraLocal extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Não foi possível ler a configuração do cliente no banco do walle.');
    }
}
