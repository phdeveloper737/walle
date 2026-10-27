<?php

namespace App\InformacoesTecnicas;

use RuntimeException;

/**
 * A gravação no banco local falhou. Propositalmente sem a exceção original: a QueryException leva o SQL com os
 * valores (inclusive as senhas), e isso não pode ir para o log nem para a tela.
 */
class FalhaGravacaoLocal extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Não foi possível gravar a configuração técnica no banco do walle.');
    }
}
