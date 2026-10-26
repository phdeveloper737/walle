<?php

namespace App\Support\HelpDesk;

use RuntimeException;

/** Tentativa de gravar no Help Desk de produção, que o walle só consulta (TravaHelpDeskProducao). */
class EscritaNoHelpDeskDeProducao extends RuntimeException
{
    public function __construct(string $host = '192.168.10.254')
    {
        parent::__construct("O Help Desk de produção ({$host}) é somente consulta: o walle não grava nada lá.");
    }
}
