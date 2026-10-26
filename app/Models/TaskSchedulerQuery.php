<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Query agendada do Task Scheduler (tabela task_scheduler_querys do banco suporte).
 * Uma query com log não pode ser apagada (a FK do log bloqueia, como no 220).
 *
 * @property int $id
 * @property int|null $fk_a_user
 * @property string $descricao
 * @property Carbon|null $data_registro
 * @property Carbon|null $data_finalizado
 * @property string|null $query_execute
 * @property string|null $finalizado S | N
 * @property string $ativo S | N
 * @property string $deletado S | N
 * @property int|null $fk_a_user_delete
 */
class TaskSchedulerQuery extends Model
{
    protected $table = 'task_scheduler_querys';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'data_registro' => 'datetime',
            'data_finalizado' => 'datetime',
        ];
    }
}
