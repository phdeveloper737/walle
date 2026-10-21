<?php

namespace App\Sactus\Ssh;

/** Sessão SSH já autenticada no servidor do cliente (uma por cliente, reaproveitada por todas as rotinas). */
interface SessaoSsh
{
    /**
     * Cada chamada vai ao servidor: o mesmo comando pode dar resultado diferente (ex.: setfacl antes e depois do yum).
     *
     * @phpstan-impure
     */
    public function executar(string $comando, int $timeoutSegundos): ResultadoComando;

    public function fechar(): void;
}
