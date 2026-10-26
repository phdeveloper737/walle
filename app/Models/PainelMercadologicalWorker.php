<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Resumo da última execução da classificação mercadológica de um cliente
 * (tabela painel_mercadological_worker do banco suporte). A tabela não tem coluna id:
 * a chave é o fk_c_cliente (único).
 *
 * @property int|null $fk_c_cliente
 * @property int|null $total
 * @property Carbon $data
 * @property int $lidos
 * @property int $fora_base
 * @property int $conferidos
 * @property int $corretos
 * @property int $divergentes
 * @property int $atualizados
 * @property int $restantes
 * @property int $lotes
 * @property int $classificacao
 * @property int $erros
 * @property string $segundos
 * @property string $parcial S | N
 * @property string $status OK | PARCIAL | ERRO
 * @property string $etapa_final
 * @property string $ultimo_erro
 * @property int $atualizados_acumulado
 */
class PainelMercadologicalWorker extends Model
{
    protected $table = 'painel_mercadological_worker';

    protected $primaryKey = 'fk_c_cliente';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'data' => 'datetime',
        ];
    }

    /**
     * Histórico do cliente (painel_mercadological_worker_log), ligado pelo fk_c_cliente.
     *
     * @return HasMany<PainelMercadologicalWorkerLog, $this>
     */
    public function logs(): HasMany
    {
        return $this->hasMany(PainelMercadologicalWorkerLog::class, 'fk_c_cliente', 'fk_c_cliente');
    }
}
