<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Configuração persistida dos robôs do walle.
 *
 * @property int $id
 * @property string $robo
 * @property string $parametro
 * @property string|null $valor
 * @property string|null $valor_sanitizado
 * @property Carbon $criado_em
 * @property Carbon $atualizado_em
 */
class RoboConfig extends Model
{
    protected $table = 'robo_config';

    const CREATED_AT = 'criado_em';

    const UPDATED_AT = 'atualizado_em';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    /**
     * Sanitiza valor removendo segredos conhecidos (case-insensitive).
     * Palavras-chave: senha, token, certificado, pfx, chave, secret, password.
     */
    public static function sanitizar(?string $valor): string
    {
        if ($valor === null || $valor === '') {
            return '';
        }

        $segredos = ['senha', 'token', 'certificado', 'pfx', 'chave', 'secret', 'password'];
        $lower = strtolower($valor);

        foreach ($segredos as $segredo) {
            if (str_contains($lower, $segredo)) {
                return '***';
            }
        }

        return $valor;
    }

    /**
     * Impede alteração da chave de negócio (robo + parametro).
     */
    protected static function booted(): void
    {
        static::updating(function (self $model) {
            if ($model->isDirty('robo') || $model->isDirty('parametro')) {
                throw new \RuntimeException('Chave de negócio imutável');
            }

        });

        static::saving(function (self $model) {
            if ($model->valor === null) {
                return;
            }

            if ($model->valor_sanitizado === null || $model->isDirty('valor')) {
                $model->valor_sanitizado = self::sanitizar($model->valor);
            }
        });
    }

    /**
     * Scope para buscar por robô.
     *
     * @param  Builder<RoboConfig>  $query
     */
    public function scopeRobo(Builder $query, string $robo): void
    {
        $query->where('robo', $robo);
    }
}
