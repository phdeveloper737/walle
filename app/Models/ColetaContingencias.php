<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Resultado de um cliente em um ciclo; totais nulos distinguem falha de uma coleta sem contingências.
 *
 * @property int $id
 * @property int $ciclo_id
 * @property int $fk_c_cliente
 * @property Carbon $iniciado_em
 * @property Carbon|null $terminado_em
 * @property string $status
 * @property string|null $erro
 * @property int|null $total_cliente
 * @property int|null $total_bot
 * @property int|null $total_sactus
 * @property int|null $total_versatus
 * @property-read CicloContingencias $ciclo
 */
class ColetaContingencias extends Model
{
    protected $table = 'contingencias_coletas';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $attributes = [
        'status' => 'em_andamento',
        'terminado_em' => null,
        'erro' => null,
        'total_cliente' => null,
        'total_bot' => null,
        'total_sactus' => null,
        'total_versatus' => null,
    ];

    protected function casts(): array
    {
        return [
            'iniciado_em' => 'datetime',
            'terminado_em' => 'datetime',
        ];
    }

    /** @return BelongsTo<CicloContingencias, $this> */
    public function ciclo(): BelongsTo
    {
        return $this->belongsTo(CicloContingencias::class, 'ciclo_id');
    }
}
