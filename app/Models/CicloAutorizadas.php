<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Um ciclo do robô das Autorizadas (tabela nova do walle).
 *
 * @property int $id
 * @property Carbon $iniciado_em
 * @property Carbon|null $terminado_em
 * @property int $removidas
 * @property int $semeadas
 * @property int $na_fila
 * @property int $consultadas
 * @property int $autorizadas
 * @property int $gravadas
 * @property int $falhas
 * @property bool $consumo_indevido
 * @property string|null $erro
 */
class CicloAutorizadas extends Model
{
    protected $table = 'autorizadas_ciclos';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $attributes = [
        'removidas' => 0,
        'semeadas' => 0,
        'na_fila' => 0,
        'consultadas' => 0,
        'autorizadas' => 0,
        'gravadas' => 0,
        'falhas' => 0,
        'consumo_indevido' => false,
    ];

    protected function casts(): array
    {
        return [
            'iniciado_em' => 'datetime',
            'terminado_em' => 'datetime',
            'consumo_indevido' => 'boolean',
        ];
    }

    public function duracaoEmSegundos(): ?int
    {
        return $this->terminado_em !== null ? (int) $this->iniciado_em->diffInSeconds($this->terminado_em) : null;
    }
}
