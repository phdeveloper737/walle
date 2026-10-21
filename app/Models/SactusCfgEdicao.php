<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Pedido de edição de uma flag do sactus.cfg (tabela sactus_cfg_edicoes do banco suporte).
 * O painel cria como PENDENTE; o robô reserva (PROCESSANDO) e termina em CONCLUIDO ou ERRO.
 *
 * @property int $id
 * @property int $fk_c_cliente
 * @property string $tipo_edicao ADICIONAR | REMOVER
 * @property string $flag
 * @property string|null $valor
 * @property string $status
 * @property string|null $mensagem
 * @property string|null $solicitante
 * @property string|null $ip
 * @property string|null $lock_token
 * @property int $tentativas
 * @property Carbon $criado_em
 * @property Carbon|null $iniciado_em
 * @property Carbon|null $processado_em
 */
class SactusCfgEdicao extends Model
{
    protected $table = 'sactus_cfg_edicoes';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $attributes = [
        'status' => 'PENDENTE',
        'tentativas' => 0,
    ];

    protected function casts(): array
    {
        return [
            'criado_em' => 'datetime',
            'iniciado_em' => 'datetime',
            'processado_em' => 'datetime',
        ];
    }
}
