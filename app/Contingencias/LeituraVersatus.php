<?php

namespace App\Contingencias;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Único ponto de leitura das contingências do Versatus: o banco "suporte" do 220 de produção (conexão legado220,
 * somente leitura), onde o Versatus grava de fato. As tabelas locais do walle só têm a importação de 20/10/2026.
 * Quando houver a API para o Versatus gravar direto no walle, a troca é só aqui.
 *
 * Tabelas: contingencias_versatus_venda.fk_contingencias_versatus = fk_c_cliente e
 * contingencias_versatus_venda_itens.fk_contingencias_versatus_venda = venda.id.
 */
class LeituraVersatus
{
    public const CONEXAO_PADRAO = 'legado220';

    /** Filtro do set.history.cont.php:314 (o robô não leva as vendas que o Versatus nem enviou à Sefaz). */
    private const MOTIVO_NAO_ENVIADA = '%Nao Enviada para Sefaz%';

    /**
     * Cabeçalhos das vendas, para o histórico do robô (set.history.cont.php:305-315).
     *
     * @return list<Contingencia>
     *
     * @throws FalhaLeituraVersatus
     */
    public function cabecalhos(int $clienteId): array
    {
        try {
            return $this->conexao()->table('contingencias_versatus_venda')
                ->where('fk_contingencias_versatus', $clienteId)
                ->where('motivo', 'not like', self::MOTIVO_NAO_ENVIADA)
                ->orderBy('id')
                ->get(['fk_company', 'pdv', 'cupom', 'data_hora', 'codigo', 'motivo', 'chave'])
                ->map(fn (object $linha): Contingencia => Contingencia::deArray([
                    'software' => Contingencia::VERSATUS,
                    'loja' => $linha->fk_company,
                    'pdv' => $linha->pdv,
                    'cupom' => $linha->cupom,
                    'emitida_em' => $linha->data_hora,
                    'codigo_motivo' => $linha->codigo,
                    'motivo' => $linha->motivo,
                    'chave' => $linha->chave,
                ]))->all();
        } catch (Throwable $erro) {
            throw new FalhaLeituraVersatus($erro);
        }
    }

    /**
     * Vendas com os itens, para a tela (get.contingencias.php:435-439, INNER JOIN nos itens).
     *
     * @return list<Contingencia>
     *
     * @throws FalhaLeituraVersatus
     */
    public function comItens(int $clienteId, int $limite): array
    {
        try {
            $linhas = $this->conexao()->table('contingencias_versatus_venda as v')
                ->join('contingencias_versatus_venda_itens as vi', 'vi.fk_contingencias_versatus_venda', '=', 'v.id')
                ->where('v.fk_contingencias_versatus', $clienteId)
                ->orderByDesc('v.data_hora')
                ->orderBy('vi.id')
                ->limit($limite)
                ->get([
                    'v.fk_company', 'v.pdv', 'v.cupom', 'v.data_hora', 'v.codigo', 'v.motivo', 'v.chave',
                    'vi.cod_barras', 'vi.fk_produto', 'vi.nome', 'vi.unidade', 'vi.aliquota', 'vi.base_reducao', 'vi.aliquota_reduzida',
                    'vi.ncm', 'vi.cest', 'vi.cbenef', 'vi.cfop', 'vi.cst', 'vi.cst_ibscbs', 'vi.classtrib_ibs',
                ]);
        } catch (Throwable $erro) {
            throw new FalhaLeituraVersatus($erro);
        }

        $vendas = [];

        foreach ($linhas as $linha) {
            $id = "{$linha->fk_company}_{$linha->pdv}_{$linha->cupom}";

            $vendas[$id] ??= [
                'software' => Contingencia::VERSATUS,
                'loja' => $linha->fk_company,
                'pdv' => $linha->pdv,
                'cupom' => $linha->cupom,
                'emitida_em' => $linha->data_hora,
                'codigo_motivo' => $linha->codigo,
                'motivo' => $linha->motivo,
                'chave' => $linha->chave,
                'itens' => [],
            ];

            $vendas[$id]['itens'][] = [
                'codigo_barras' => $linha->cod_barras,
                'id_produto' => $linha->fk_produto,
                'nome' => $linha->nome,
                'unidade' => $linha->unidade,
                'aliquota' => $linha->aliquota,
                'reducao' => $linha->base_reducao,
                'aliquota_reduzida' => $linha->aliquota_reduzida,
                'ncm' => $linha->ncm,
                'cest' => $linha->cest,
                'cbenef' => $linha->cbenef,
                'cfop' => $linha->cfop,
                'cst' => $linha->cst,
                'cst_ibscbs' => $linha->cst_ibscbs,
                'classtrib_ibs' => $linha->classtrib_ibs,
                'chave' => $linha->chave,
            ];
        }

        return array_values(array_map(Contingencia::deArray(...), $vendas));
    }

    /**
     * Cliente só Versatus (servidor_multiempresa = 'V' no Help Desk): não há Sactus para consultar no servidor dele.
     *
     * @param  array<string, mixed>  $servidor
     */
    public static function somenteVersatus(array $servidor): bool
    {
        return trim((string) ($servidor['servidor_multiempresa'] ?? '')) === 'V';
    }

    private function conexao(): Connection
    {
        return DB::connection((string) config('services.clientes.conexao_versatus', self::CONEXAO_PADRAO));
    }
}
