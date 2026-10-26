<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Resultado de uma query agendada num cliente (tabela task_scheduler_querys_log do banco suporte).
 * A tabela não tem chave primária: o log só é gravado e lido, nunca alterado pelo model.
 *
 * @property int|null $fk_task_scheduler_querys
 * @property int|null $fk_c_cliente
 * @property string|null $log_query
 * @property Carbon $data_time
 * @property-read TaskSchedulerQuery|null $taskSchedulerQuery
 */
class TaskSchedulerQueryLog extends Model
{
    protected $table = 'task_scheduler_querys_log';

    protected $primaryKey = null;

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'data_time' => 'datetime',
        ];
    }

    /** @return BelongsTo<TaskSchedulerQuery, $this> */
    public function taskSchedulerQuery(): BelongsTo
    {
        return $this->belongsTo(TaskSchedulerQuery::class, 'fk_task_scheduler_querys');
    }
}
