<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Total de contingências de um cliente na última coleta do Contingência BOT (tabela painel_contingency_crawler
 * do banco suporte). Uma linha por cliente; a tabela não tem id, a chave é fk_c_cliente.
 *
 * @property int $fk_c_cliente
 * @property int|null $total_cliente
 * @property int|null $total_bot
 * @property int|null $total_sactus
 * @property int|null $total_versatus
 * @property Carbon $data
 * @property-read Collection<int, ColetaContingencias> $coletas
 * @property-read ColetaContingencias|null $ultimaColeta
 */
class PainelContingencyCrawler extends Model
{
    protected $table = 'painel_contingency_crawler';

    protected $primaryKey = 'fk_c_cliente';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'data' => 'datetime',
        ];
    }

    /** @return HasMany<ColetaContingencias, $this> */
    public function coletas(): HasMany
    {
        return $this->hasMany(ColetaContingencias::class, 'fk_c_cliente', 'fk_c_cliente');
    }

    /** @return HasOne<ColetaContingencias, $this> */
    public function ultimaColeta(): HasOne
    {
        return $this->hasOne(ColetaContingencias::class, 'fk_c_cliente', 'fk_c_cliente')->latestOfMany();
    }
}
