<?php

namespace App\Contingencias;

use Illuminate\Support\Facades\DB;

/**
 * Remove as vendas do Versatus em contingência de um cliente (substitui consultorias/api/delete.php do 220).
 * O 220 apagava só as vendas, com foreign_key_checks desligado; aqui as vendas e os itens saem juntos numa transação.
 * Só mexe no banco walle.
 */
class RemocaoDeCliente
{
    /**
     * @return int total de linhas apagadas (vendas + itens); 0 quando o id é inválido ou não há nada do cliente
     */
    public function remover(int $clienteId): int
    {
        if ($clienteId <= 0) {
            return 0;
        }

        return DB::transaction(function () use ($clienteId): int {
            $vendas = DB::table('contingencias_versatus_venda')
                ->where('fk_contingencias_versatus', $clienteId)
                ->pluck('id');

            if ($vendas->isEmpty()) {
                return 0;
            }

            $itens = DB::table('contingencias_versatus_venda_itens')
                ->whereIn('fk_contingencias_versatus_venda', $vendas)
                ->delete();

            $apagadas = DB::table('contingencias_versatus_venda')
                ->where('fk_contingencias_versatus', $clienteId)
                ->delete();

            return $itens + $apagadas;
        });
    }
}
