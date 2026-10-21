<?php

namespace App\Contingencias;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Lista de clientes da tela de Contingências, lida do Help Desk (192.168.10.254).
 *
 * Mesma consulta do sistema antigo (consultorias/api/get.list.php): clientes não deletados,
 * em ordem de razão social, com o total de contingências do PDV Linux e do Versatus.
 */
class ListaDeClientes
{
    /** O sistema antigo esconde o cliente 18 da lista. */
    public const CLIENTES_OCULTOS = [18];

    private const CHAVE_CACHE = 'contingencias:lista-clientes';

    /** @return list<ClienteDaLista> */
    public function todos(): array
    {
        // No cache vão só arrays (o cache do Laravel 13 não desserializa objetos)
        $linhas = Cache::remember(self::CHAVE_CACHE, now()->addMinutes(2), fn (): array => $this->consultar());

        return array_map(ClienteDaLista::deLinha(...), $linhas);
    }

    /**
     * id => razão social, para as telas que só precisam do nome. Vazio se o Help Desk estiver fora do ar.
     *
     * @return array<int, string>
     */
    public function nomes(): array
    {
        try {
            return array_column(array_map(fn (ClienteDaLista $c): array => ['id' => $c->id, 'nome' => $c->razaoSocial], $this->todos()), 'nome', 'id');
        } catch (QueryException $e) {
            report($e);

            return [];
        }
    }

    public function esquecer(): void
    {
        Cache::forget(self::CHAVE_CACHE);
    }

    /** @return list<array<string, mixed>> */
    private function consultar(): array
    {
        $help = DB::connection('help');

        return $help->table('c_cliente as c')
            ->leftJoin('informacao_tecnica_dataserver as d', 'd.fk_c_cliente', '=', 'c.id')
            ->where('c.deletado', 'N')
            ->whereNotIn('c.id', self::CLIENTES_OCULTOS)
            ->select([
                'c.id', 'c.consultoria', 'c.cnpj', 'c.razao_social', 'c.ultima_atualizacao',
                'd.loja', 'd.servidor_multiempresa', 'd.local_servidor', 'd.ip_externo_servidor', 'd.pasta_multiempresa',
            ])
            ->selectSub(
                $help->table('informacao_tecnica_pdv_linux')->selectRaw('SUM(contingencias)')->whereColumn('fk_c_cliente', 'c.id'),
                'total_linux',
            )
            ->selectSub(
                $help->table('informacao_atua_versatus')->selectRaw('SUM(contigencia)')->whereColumn('fk_c_cliente', 'c.id'),
                'total_versatus',
            )
            ->orderBy('c.razao_social')
            ->limit(5000)
            ->get()
            // Um card por cliente, mesmo que um dia apareça mais de um cadastro de servidor
            ->unique('id')
            ->map(fn (object $linha): array => (array) $linha)
            ->values()
            ->all();
    }
}
