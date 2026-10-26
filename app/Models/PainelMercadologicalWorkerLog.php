<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Uma etapa da classificação mercadológica de um cliente
 * (tabela painel_mercadological_worker_log do banco suporte).
 *
 * @property int $id
 * @property int $fk_c_cliente
 * @property string $tipo ERROR | INFO | SUCCESS
 * @property string $etapa ex.: CONEXAO_300, TRUNCATE, REPLACE_GRUPO
 * @property string $mensagem
 * @property Carbon $criado_em
 */
class PainelMercadologicalWorkerLog extends Model
{
    protected $table = 'painel_mercadological_worker_log';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'criado_em' => 'datetime',
        ];
    }
}
