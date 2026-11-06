<?php

namespace App\Support\HelpDesk;

use RuntimeException;

/**
 * Tentativa de gravar num banco de produção que o walle só consulta (TravaHelpDeskProducao): o Help Desk
 * (192.168.10.254) ou o banco "suporte" do sistema antigo (192.168.10.220, conexão legado220).
 */
class EscritaNoHelpDeskDeProducao extends RuntimeException
{
    public const HELP_DESK = 'O Help Desk de produção';

    public const SISTEMA_ANTIGO = 'O banco do sistema antigo (220) de produção';

    public function __construct(string $host = '192.168.10.254', string $sistema = self::HELP_DESK)
    {
        parent::__construct("{$sistema} ({$host}) é somente consulta: o walle não grava nada lá.");
    }
}
