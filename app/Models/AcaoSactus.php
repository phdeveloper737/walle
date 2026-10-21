<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Ação manual no servidor de um cliente, pedida por um admin (tabela nova do walle).
 *
 * @property int $id
 * @property int $fk_c_cliente
 * @property string $acao YUM_UPDATE | INSTALAR_ACL
 * @property string $status PENDENTE | PROCESSANDO | CONCLUIDO | ERRO
 * @property int|null $rc
 * @property string|null $saida
 * @property string|null $erro
 * @property string|null $alvo
 * @property int $solicitante_id
 * @property string $solicitante
 * @property string|null $ip
 * @property Carbon $criado_em
 * @property Carbon|null $iniciado_em
 * @property Carbon|null $processado_em
 */
class AcaoSactus extends Model
{
    public const ACOES = [
        'YUM_UPDATE' => 'Atualizar o sistema (yum -y update)',
        'INSTALAR_ACL' => 'Instalar o pacote acl (yum install -y acl)',
    ];

    protected $table = 'sactus_acoes';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $attributes = [
        'status' => 'PENDENTE',
    ];

    protected function casts(): array
    {
        return [
            'criado_em' => 'datetime',
            'iniciado_em' => 'datetime',
            'processado_em' => 'datetime',
        ];
    }

    public function rotulo(): string
    {
        return self::ACOES[$this->acao] ?? $this->acao;
    }

    public function aberta(): bool
    {
        return in_array($this->status, ['PENDENTE', 'PROCESSANDO'], true);
    }
}
