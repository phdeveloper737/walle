<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Configuração técnica de acesso ao servidor de um cliente usada pelo Contingência BOT
 * (tabela informacao_tecnica_painel_contingencias do banco suporte). Uma por cliente: o 220 grava id = fk_c_cliente,
 * por isso o id não é protegido contra atribuição em massa.
 *
 * @property int $id
 * @property int|null $fk_c_cliente
 * @property string|null $id_loja
 * @property string|null $cnpj
 * @property string|null $razao_social
 * @property string|null $nome_fantasia
 * @property string|null $tips_server
 * @property string|null $tips_pdv
 * @property string|null $ip_docker_principal
 * @property string $banco_separado_docker S | N
 * @property string|null $ip_container_docker
 * @property string|null $porta_http_docker
 * @property string|null $porta_mysql_docker
 * @property string|null $porta_ssh_docker
 * @property string|null $porta_ssh_container_docker
 * @property string|null $senha_ssh_docker
 * @property string|null $banco_mysql_docker
 * @property string|null $senha_mysql_docker
 * @property string|null $usuario_mysql_docker
 * @property string|null $ip_externo_servidor
 * @property string|null $servidor_mysql
 * @property string|null $porta_http
 * @property string|null $porta_mysql
 * @property string|null $porta_ssh
 * @property string|null $banco_mysql
 * @property string|null $senha_mysql
 * @property string|null $senha_ssh
 * @property string $ativo_painel_contingencias S | N
 * @property string $deletado S | N
 * @property Carbon|null $ultima_atualizacao
 * @property string|null $ip_publico
 * @property string|null $emissao_cupons S | N
 */
class InformacaoTecnicaPainelContingencias extends Model
{
    protected $table = 'informacao_tecnica_painel_contingencias';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'ultima_atualizacao' => 'datetime',
        ];
    }
}
