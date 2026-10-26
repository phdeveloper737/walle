<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Quem fez o quê (tabela nova do walle). Use sempre Auditoria::registrar().
 *
 * @property int $id
 * @property int|null $usuario_id
 * @property string $usuario
 * @property string $acao
 * @property int|null $fk_c_cliente
 * @property array<string, mixed>|null $detalhes
 * @property string|null $ip
 * @property Carbon $criado_em
 */
class Auditoria extends Model
{
    protected $table = 'auditoria';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'detalhes' => 'array',
            'criado_em' => 'datetime',
        ];
    }

    /**
     * Registra a ação de quem está logado. Nunca passe senha em $detalhes.
     *
     * @param  array<string, mixed>  $detalhes
     * @param  string|null  $conexao  Conexão onde gravar; null usa a padrão. A conexão `auditoria` serve para o registro que não pode ser desfeito junto com a transação em curso (ex.: escrita barrada no Help Desk).
     */
    public static function registrar(string $acao, ?int $clienteId = null, array $detalhes = [], ?string $conexao = null): self
    {
        $usuario = auth()->user();

        return static::on($conexao)->create([
            'usuario_id' => $usuario instanceof Usuario ? $usuario->id : null,
            'usuario' => Usuario::nomeDoLogado(),
            'acao' => $acao,
            'fk_c_cliente' => $clienteId,
            'detalhes' => $detalhes === [] ? null : $detalhes,
            'ip' => app()->runningInConsole() ? null : request()->ip(),
            'criado_em' => now(),
        ]);
    }
}
