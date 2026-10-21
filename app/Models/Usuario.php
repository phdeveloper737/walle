<?php

namespace App\Models;

use App\Models\Concerns\SomenteLeitura;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\User as Authenticatable;

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
    use SomenteLeitura;

    protected $connection = 'help';

    protected $table = 'a_user';

    public $timestamps = false;

    /** A senha que o login confere é a tx_senha (a coluna "password" não é usada). */
    protected $authPasswordName = 'tx_senha';

    /** Sem "lembrar de mim": o a_user não tem remember_token (o logout procuraria a coluna). */
    protected $rememberTokenName = '';

    protected $hidden = ['password', 'tx_senha'];

    /** @param Builder<Usuario> $query */
    public function scopeAtivos(Builder $query): void
    {
        $query->where('deletado', 'N');
    }

    public function primeiroNome(): string
    {
        return explode(' ', trim($this->name))[0];
    }

    /** Nome de quem está logado, para os registros de quem fez o quê. */
    public static function nomeDoLogado(): string
    {
        $usuario = auth()->user();

        return $usuario instanceof self ? $usuario->name : 'desconhecido';
    }
}
