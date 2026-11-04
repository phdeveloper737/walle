<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Histórico imutável de alterações da robo_config (tabela nova do walle).
 *
 * Os valores brutos (`valor_anterior`, `valor_novo`) podem conter segredo: telas devem exibir só os `*_sanitizado`.
 *
 * @property int $id
 * @property string $robo
 * @property string $parametro
 * @property string $acao
 * @property string|null $valor_anterior
 * @property string|null $valor_novo
 * @property string|null $valor_anterior_sanitizado
 * @property string|null $valor_novo_sanitizado
 * @property string $ator
 * @property string|null $ip
 * @property Carbon $criado_em
 */
class RoboConfigHistorico extends Model
{
    protected $table = 'robo_config_historico';

    public $timestamps = false;

    protected $guarded = ['id'];

    /**
     * @var list<string>
     */
    protected $hidden = ['valor_anterior', 'valor_novo'];

    protected function casts(): array
    {
        return [
            'criado_em' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->criado_em = $model->criado_em ?? now();
        });

        static::updating(function (): void {
            throw new \RuntimeException('Histórico imutável');
        });

        static::deleting(function (): void {
            throw new \RuntimeException('Histórico imutável');
        });
    }
}
