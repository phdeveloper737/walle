<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Um ciclo do robô do Sactus (tabela nova do walle).
 *
 * @property int $id
 * @property Carbon $iniciado_em
 * @property Carbon|null $terminado_em
 * @property int $elegiveis
 * @property int $sem_credenciais
 * @property int $offline
 * @property int $coletados
 * @property int $falhas
 * @property int $acl_ok
 * @property int $acl_falha
 * @property int $erp_ok
 * @property int $erp_falha
 * @property int $cfg_ok
 * @property int $cfg_falha
 * @property string|null $erro
 */
class CicloSactus extends Model
{
    protected $table = 'sactus_ciclos';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $attributes = [
        'elegiveis' => 0,
        'sem_credenciais' => 0,
        'offline' => 0,
        'coletados' => 0,
        'falhas' => 0,
        'acl_ok' => 0,
        'acl_falha' => 0,
        'erp_ok' => 0,
        'erp_falha' => 0,
        'cfg_ok' => 0,
        'cfg_falha' => 0,
    ];

    protected function casts(): array
    {
        return [
            'iniciado_em' => 'datetime',
            'terminado_em' => 'datetime',
        ];
    }

    public function duracaoEmSegundos(): ?int
    {
        return $this->terminado_em !== null ? (int) $this->iniciado_em->diffInSeconds($this->terminado_em) : null;
    }
}
