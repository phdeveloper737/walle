<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Entradas e saídas dos usuários (tabela a_user_acessos, mesma estrutura do suporte).
 *
 * @property int $id
 * @property int $fk_a_user
 * @property Carbon|null $data_hora_login
 * @property int $qtd_atendimento_login
 * @property Carbon|null $data_hora_logout
 * @property int $qtd_atendimento_logout
 */
class AcessoUsuario extends Model
{
    protected $table = 'a_user_acessos';

    public $timestamps = false;

    protected $fillable = [
        'fk_a_user',
        'data_hora_login',
        'qtd_atendimento_login',
        'data_hora_logout',
        'qtd_atendimento_logout',
    ];

    protected function casts(): array
    {
        return [
            'data_hora_login' => 'datetime',
            'data_hora_logout' => 'datetime',
        ];
    }
}
