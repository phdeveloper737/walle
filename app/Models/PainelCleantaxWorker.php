<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Última chamada do CleanTax BOT ao cleantaxbot.php de um cliente (tabela painel_cleantax_worker do banco suporte).
 * A tabela não tem coluna id: a chave é o fk_c_cliente (único). A coluna data se atualiza sozinha em todo UPDATE.
 *
 * @property int $fk_c_cliente
 * @property string|null $empresa
 * @property string|null $cnpj
 * @property string|null $servidor
 * @property string|null $porta_http
 * @property string|null $banco_dados
 * @property string|null $tempo_execucao_segundos
 * @property int|null $http_code
 * @property string|null $status_execucao
 * @property int|null $curl_errno
 * @property string|null $mensagem
 * @property string|null $url
 * @property string|null $response_preview
 * @property Carbon|null $data_inicio
 * @property Carbon|null $data_fim
 * @property Carbon $data
 */
class PainelCleantaxWorker extends Model
{
    protected $table = 'painel_cleantax_worker';

    protected $primaryKey = 'fk_c_cliente';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'tempo_execucao_segundos' => 'decimal:3',
            'data_inicio' => 'datetime',
            'data_fim' => 'datetime',
            'data' => 'datetime',
        ];
    }
}
