<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Histórico local de um ciclo do Contingência BOT.
 *
 * @property int $id
 * @property Carbon $iniciado_em
 * @property Carbon|null $terminado_em
 * @property string $status
 * @property int $elegiveis
 * @property int $coletados
 * @property int $falhas
 * @property string|null $erro
 * @property-read Collection<int, ColetaContingencias> $coletas
 */
class CicloContingencias extends Model
{
    protected $table = 'contingencias_ciclos';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $attributes = [
        'status' => 'em_andamento',
        'elegiveis' => 0,
        'coletados' => 0,
        'falhas' => 0,
        'terminado_em' => null,
        'erro' => null,
    ];

    protected function casts(): array
    {
        return [
            'iniciado_em' => 'datetime',
            'terminado_em' => 'datetime',
        ];
    }

    /** @return HasMany<ColetaContingencias, $this> */
    public function coletas(): HasMany
    {
        return $this->hasMany(ColetaContingencias::class, 'ciclo_id');
    }
}
