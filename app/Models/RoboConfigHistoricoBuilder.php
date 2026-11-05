<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/**
 * Builder append-only do RoboConfigHistorico: bloqueia escrita em massa que não dispara eventos do Eloquent.
 *
 * @extends Builder<RoboConfigHistorico>
 */
class RoboConfigHistoricoBuilder extends Builder
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function update(array $values): int
    {
        throw new RuntimeException('Histórico imutável');
    }

    public function delete(): mixed
    {
        throw new RuntimeException('Histórico imutável');
    }

    public function forceDelete(): mixed
    {
        throw new RuntimeException('Histórico imutável');
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public function increment($column, $amount = 1, array $extra = []): int
    {
        throw new RuntimeException('Histórico imutável');
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public function decrement($column, $amount = 1, array $extra = []): int
    {
        throw new RuntimeException('Histórico imutável');
    }
}
