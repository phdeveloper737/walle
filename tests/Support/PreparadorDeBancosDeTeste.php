<?php

namespace Tests\Support;

use Illuminate\Database\DatabaseManager;
use RuntimeException;

final class PreparadorDeBancosDeTeste
{
    public function __construct(private readonly DatabaseManager $databaseManager) {}

    /**
     * @param  array<string, mixed>  $configuracao
     */
    public function preparar(array $configuracao): void
    {
        $banco = (string) ($configuracao['database'] ?? '');

        self::exigirNomeSeguro($banco);

        $servidor = $this->databaseManager->build(array_merge($configuracao, [
            'database' => null,
            'url' => null,
        ]));

        try {
            $servidor->statement("CREATE DATABASE IF NOT EXISTS `{$banco}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
            $servidor->statement("CREATE DATABASE IF NOT EXISTS `{$banco}_help` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
        } finally {
            $servidor->disconnect();
        }
    }

    public static function exigirNomeSeguro(string $banco): void
    {
        if (preg_match('/^walle_testes(_t[0-9]+)?\z/', $banco) !== 1) {
            throw new RuntimeException("Banco de testes inválido: \"{$banco}\". Use walle_testes ou walle_testes_tNN.");
        }
    }
}
