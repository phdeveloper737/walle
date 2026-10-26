<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Última coleta do Bot Sentinela de um cliente (tabela painel_sentinela do banco suporte).
 * Uma linha por cliente (índice único uq_cliente); quem grava é o robô do Sentinela.
 *
 * @property int $id
 * @property int $fk_c_cliente
 * @property string $status
 * @property int $http_code
 * @property string $erro
 * @property string $host
 * @property string $url
 * @property string $hostname
 * @property string $os
 * @property string $kernel
 * @property string $uptime
 * @property string $php
 * @property string|null $cpu_uso
 * @property int $cpu_nucleos
 * @property string $load1
 * @property string $load5
 * @property string $load15
 * @property int $mem_total_mb
 * @property int $mem_usado_mb
 * @property string $mem_pct
 * @property string $swap_pct
 * @property string $disco_mount
 * @property string $disco_total_gb
 * @property string $disco_usado_gb
 * @property int $disco_pct
 * @property string $sactus_rodando
 * @property string $sactus_mtime
 * @property string $sactus_md5_ok
 * @property string $sactus_versao
 * @property string $vpn_ativo
 * @property string $vpn_tun
 * @property int $vpn_mtu
 * @property string $vpn_inconsistente
 * @property int $proc_total
 * @property array<string, mixed>|null $json
 * @property int $duracao_ms
 * @property Carbon $data
 */
class PainelSentinela extends Model
{
    protected $table = 'painel_sentinela';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'json' => 'array',
            'data' => 'datetime',
        ];
    }
}
