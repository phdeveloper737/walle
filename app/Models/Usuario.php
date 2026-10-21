<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\User as Authenticatable;
use LogicException;

/**
 * Usuário do Help Desk (tabela a_user do 192.168.10.254).
 *
 * Somente leitura: o cadastro de usuários continua sendo feito no Help Desk.
 *
 * @property int $id
 * @property string $name
 * @property string $user
 * @property string $deletado
 */
class Usuario extends Authenticatable
{
    protected $connection = 'help';

    protected $table = 'a_user';

    public $timestamps = false;

    /** A senha que o login confere é a tx_senha (a coluna "password" não é usada). */
    protected $authPasswordName = 'tx_senha';

    /** Sem "lembrar de mim": o a_user não tem remember_token (o logout procuraria a coluna). */
    protected $rememberTokenName = '';

    protected $hidden = ['password', 'tx_senha'];

    protected static function booted(): void
    {
        static::saving(function (): void {
            throw new LogicException('A tabela a_user é do Help Desk e é somente leitura no walle.');
        });

        static::deleting(function (): void {
            throw new LogicException('A tabela a_user é do Help Desk e é somente leitura no walle.');
        });
    }

    /** @param Builder<Usuario> $query */
    public function scopeAtivos(Builder $query): void
    {
        $query->where('deletado', 'N');
    }

    public function primeiroNome(): string
    {
        return explode(' ', trim($this->name))[0];
    }
}
