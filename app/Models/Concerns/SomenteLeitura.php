<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Model somente leitura: qualquer tentativa de gravar ou apagar lança exceção.
 * Usado nas tabelas do Help Desk (192.168.10.254), que o walle só consulta.
 */
trait SomenteLeitura
{
    protected static function bootSomenteLeitura(): void
    {
        $bloquear = function (Model $model): void {
            throw new LogicException('A tabela '.$model->getTable().' é do Help Desk e é somente leitura no walle.');
        };

        static::saving($bloquear);
        static::deleting($bloquear);
    }
}
