<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Última coleta do /etc/sactus.cfg de um cliente (tabela painel_sactus_cfg do banco suporte).
 * Quem grava é o RoboSactus; a tela só lê.
 *
 * @property int $id
 * @property int $fk_c_cliente
 * @property string|null $metodo
 * @property string|null $host
 * @property int|null $porta
 * @property string|null $usuario
 * @property string $status
 * @property string|null $erro
 * @property string|null $conteudo
 * @property string|null $json
 * @property string $acl_status
 * @property string $acl_acao
 * @property string $acl_erro
 * @property string|null $acl_json
 * @property Carbon|null $acl_data
 * @property string $erp_status
 * @property string $erp_acao
 * @property string $erp_erro
 * @property string|null $erp_json
 * @property Carbon|null $erp_data
 * @property int $tentativas
 * @property int $duracao_ms
 * @property Carbon|null $data
 */
class PainelSactusCfg extends Model
{
    protected $table = 'painel_sactus_cfg';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'acl_data' => 'datetime',
            'erp_data' => 'datetime',
            'data' => 'datetime',
        ];
    }

    public function coletado(): bool
    {
        return $this->status === 'S';
    }

    /** @return array<string, string> flag => valor (só as de primeiro nível, como no painel antigo) */
    public function flags(): array
    {
        $dados = json_decode((string) $this->json, true);
        $flags = [];

        foreach (is_array($dados) ? $dados : [] as $flag => $valor) {
            if (! is_array($valor)) {
                $flags[(string) $flag] = $valor === null ? '' : (string) $valor;
            }
        }

        return $flags;
    }

    /** @return list<array<string, mixed>> */
    public function itensAcl(): array
    {
        return self::lista($this->acl_json);
    }

    /** @return list<array<string, mixed>> */
    public function itensErp(): array
    {
        return self::lista($this->erp_json);
    }

    /** @return list<array<string, mixed>> */
    private static function lista(?string $json): array
    {
        $dados = json_decode((string) $json, true);

        return is_array($dados) ? array_values(array_filter($dados, 'is_array')) : [];
    }
}
