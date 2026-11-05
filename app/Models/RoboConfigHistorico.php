<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;

/**
 * Histórico imutável de alterações da robo_config (tabela nova do walle).
 *
 * Apenas valores sanitizados são armazenados (colunas `valor_anterior_sanitizado`, `valor_novo_sanitizado`).
 * Append-only na aplicação (o usuário walle não tem SUPER, então não há triggers): o builder customizado
 * bloqueia update()/delete()/forceDelete()/increment()/decrement() e os eventos updating/deleting bloqueiam
 * a instância, como defesa em profundidade.
 *
 * @property int $id
 * @property string $robo
 * @property string $parametro
 * @property string $acao
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

    protected function casts(): array
    {
        return [
            'criado_em' => 'datetime',
        ];
    }

    /**
     * @param  Builder  $query
     */
    public function newEloquentBuilder($query): RoboConfigHistoricoBuilder
    {
        return new RoboConfigHistoricoBuilder($query);
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
