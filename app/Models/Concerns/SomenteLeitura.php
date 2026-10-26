<?php

namespace App\Models\Concerns;

use App\Support\HelpDesk\TravaHelpDeskProducao;
use Closure;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Model somente leitura: qualquer tentativa de gravar ou apagar fica na auditoria
 * (help_desk.escrita_barrada, instrução eloquent_save ou eloquent_delete) e lança exceção.
 * Usado nas tabelas do Help Desk (192.168.10.254), que o walle só consulta.
 */
trait SomenteLeitura
{
    protected static function bootSomenteLeitura(): void
    {
        $bloquear = fn (string $instrucao): Closure => function (Model $model) use ($instrucao): void {
            $conexao = $model->getConnectionName() ?? (string) config('database.default');
            $host = config('database.connections.'.$conexao.'.host');

            TravaHelpDeskProducao::registrarTentativa(
                $conexao,
                is_string($host) && trim($host) !== '' ? trim($host) : $conexao,
                $instrucao,
            );

            throw new LogicException('A tabela '.$model->getTable().' é do Help Desk e é somente leitura no walle.');
        };

        static::saving($bloquear('eloquent_save'));
        static::deleting($bloquear('eloquent_delete'));
    }
}
