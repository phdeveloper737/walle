<?php

namespace App\Sactus;

use Illuminate\Support\Facades\DB;

/**
 * Servidores que o robô do Sactus visita, lidos do Help Desk (192.168.10.254).
 * Mesmo SQL do getServer() do sactus.php antigo.
 */
class ServidoresSactus
{
    /** @return list<ClienteSactus> */
    public function todos(): array
    {
        $prefixoVpn = (string) config('walle.sactus.erp.vpn_prefixo');

        return DB::connection('help')
            ->table('informacao_tecnica_dataserver as i')
            ->join('c_cliente as c', 'c.id', '=', 'i.fk_c_cliente')
            ->where('c.deletado', 'N')
            ->where(fn ($q) => $q->where('i.pasta_multiempresa', 'S')
                ->orWhereRaw("UPPER(TRIM(i.local_servidor)) = 'EMPRESA'")
                ->orWhere(fn ($q) => $q->whereRaw('TRIM(i.ip_interno_servidor) LIKE ?', [$prefixoVpn.'%'])
                    ->whereRaw("UPPER(TRIM(i.atualizar_erp)) = 'S'")))
            ->whereNotIn('c.id', config('walle.sactus.clientes_ignorados'))
            ->select('i.*', 'c.id as id_client')
            ->orderBy('c.id')
            ->get()
            ->unique('id_client')
            ->map(fn (object $linha): ClienteSactus => ClienteSactus::daLinha((array) $linha))
            ->values()
            ->all();
    }

    /**
     * Resumo do que o robô faria (para o painel mostrar antes de ligar).
     *
     * @return array{elegiveis: int, docker: int, empresa: int, acl: int, erp: int, erp_vpn: int}
     */
    public function resumo(bool $comandosErpConfigurados): array
    {
        $clientes = array_filter($this->todos(), fn (ClienteSactus $c): bool => $c->elegivel($comandosErpConfigurados));

        return [
            'elegiveis' => count($clientes),
            'docker' => count(array_filter($clientes, fn (ClienteSactus $c): bool => $c->ehDocker())),
            'empresa' => count(array_filter($clientes, fn (ClienteSactus $c): bool => strtoupper($c->campo('local_servidor')) === 'EMPRESA')),
            'acl' => count(array_filter($clientes, fn (ClienteSactus $c): bool => $c->precisaAcl())),
            'erp' => count(array_filter($clientes, fn (ClienteSactus $c): bool => $c->precisaAtualizarErp($comandosErpConfigurados))),
            'erp_vpn' => count(array_filter($clientes, fn (ClienteSactus $c): bool => $c->erpPorVpn($comandosErpConfigurados))),
        ];
    }
}
