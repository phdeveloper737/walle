<?php

namespace App\Contingencias;

use App\Contingencias\Cliente\ConectorDeCliente;
use App\Contingencias\Cliente\ConexaoComCliente;
use App\Contingencias\Cliente\FalhaConexaoCliente;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Contingências de um cliente (substitui consultorias/api/get.contingencias.php), SÓ LEITURA:
 *
 * - Versatus: tabelas contingencias_versatus_venda(+itens) do banco walle;
 * - Sactus/PDV Linux: tabela contingencias_pdv no servidor do cliente (mesmas faixas de motivo do sistema antigo);
 * - as que o robô já viu autorizadas na Sefaz (history_contingencia_auth) saem da lista de pendentes.
 *
 * O resultado fica 2 minutos no cache para a tela não consultar o servidor do cliente a cada clique.
 */
class BuscaContingencias
{
    /** Máximo de contingências por cliente (igual ao sistema antigo). */
    public const LIMITE = 1000;

    /** Faixas de motivo do sistema antigo: traz um pouco de cada tipo de erro, não só as mais recentes. */
    public const FAIXAS_DE_MOTIVO = ['CST', 'NCM', 'CFOP', 'CSOSN', 'schema', 'fiscal', ''];

    public function __construct(private readonly ConectorDeCliente $conector) {}

    public function buscar(int $clienteId): ResultadoContingencias
    {
        $dados = Cache::remember($this->chaveCache($clienteId), now()->addMinutes(2), fn (): array => $this->consultar($clienteId)->paraArray());

        return ResultadoContingencias::deArray($dados);
    }

    public function esquecer(int $clienteId): void
    {
        Cache::forget($this->chaveCache($clienteId));
    }

    private function chaveCache(int $clienteId): string
    {
        return "contingencias:cliente:{$clienteId}";
    }

    private function consultar(int $clienteId): ResultadoContingencias
    {
        $servidor = $this->servidorNoHelpDesk($clienteId) ?? throw FalhaConexaoCliente::semCadastroNoHelpDesk();

        $contingencias = $this->versatus($clienteId);
        $totalNoPdv = 0;
        $aviso = null;

        try {
            $conexao = $this->conector->conectar($clienteId, $servidor);

            try {
                [$sactus, $totalNoPdv] = $this->sactus($conexao, (string) ($servidor['loja'] ?? ''));
                array_push($contingencias, ...$sactus);
            } finally {
                $conexao->desconectar();
            }
        } catch (FalhaConexaoCliente $falha) {
            report($falha);
            $aviso = $falha->getMessage();
        } catch (QueryException $erro) {
            report($erro);
            $aviso = 'O servidor do cliente respondeu, mas a leitura das contingências do Sactus falhou (tabela com problema?).';
        }

        $contingencias = array_slice($this->semRepetidas($contingencias), 0, self::LIMITE);

        [$pendentes, $autorizadas] = $this->separarAutorizadas($clienteId, $contingencias);

        return new ResultadoContingencias($pendentes, $autorizadas, $totalNoPdv, $aviso, now()->format('H:i:s'));
    }

    /** @return array<string, mixed>|null */
    private function servidorNoHelpDesk(int $clienteId): ?array
    {
        $linha = DB::connection('help')->table('informacao_tecnica_dataserver as i')
            ->join('c_cliente as c', 'c.id', '=', 'i.fk_c_cliente')
            ->where('c.id', $clienteId)
            ->orderByDesc('i.id')
            ->select('i.*', 'c.cnpj')
            ->first();

        return $linha ? array_map(fn ($valor) => is_string($valor) ? trim($valor) : $valor, (array) $linha) : null;
    }

    /** @return list<Contingencia> */
    private function versatus(int $clienteId): array
    {
        $linhas = DB::table('contingencias_versatus_venda as v')
            ->join('contingencias_versatus_venda_itens as vi', 'vi.fk_contingencias_versatus_venda', '=', 'v.id')
            ->where('v.fk_contingencias_versatus', $clienteId)
            ->orderByDesc('v.data_hora')
            ->orderBy('vi.id')
            ->limit(self::LIMITE)
            ->get([
                'v.fk_company', 'v.pdv', 'v.cupom', 'v.data_hora', 'v.codigo', 'v.motivo', 'v.chave',
                'vi.cod_barras', 'vi.fk_produto', 'vi.nome', 'vi.unidade', 'vi.aliquota', 'vi.base_reducao', 'vi.aliquota_reduzida',
                'vi.ncm', 'vi.cest', 'vi.cbenef', 'vi.cfop', 'vi.cst', 'vi.cst_ibscbs', 'vi.classtrib_ibs',
            ]);

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

    /** @return array{0: list<Contingencia>, 1: int} contingências e total que o PDV tem pendente */
    private function sactus(ConexaoComCliente $cliente, string $loja): array
    {
        $tabela = $cliente->tabela('contingencias_pdv');
        $total = (int) ($cliente->conexao->selectOne("SELECT COUNT(*) AS total FROM {$tabela} WHERE store = ?", [$loja])->total ?? 0);

        if ($total === 0) {
            return [[], 0];
        }

        $porFaixa = intdiv(self::LIMITE, count(self::FAIXAS_DE_MOTIVO) - 1);
        $cabecalhos = [];

        foreach (self::FAIXAS_DE_MOTIVO as $faixa) {
            $sql = "SELECT * FROM {$tabela} WHERE store = ?".($faixa !== '' ? ' AND motivo LIKE ?' : '')." ORDER BY fiscal_date DESC LIMIT {$porFaixa}";

            foreach ($cliente->conexao->select($sql, $faixa !== '' ? [$loja, "%{$faixa}%"] : [$loja]) as $linha) {
                $linha = (array) $linha;
                $cabecalhos["{$linha['store']}_{$linha['pdv']}_{$linha['cupom']}"] ??= $linha;
            }

            if (count($cabecalhos) >= self::LIMITE) {
                break;
            }
        }

        $itens = $this->itensSactus($cliente, $loja, array_unique(array_column($cabecalhos, 'cupom')));

        $contingencias = [];

        foreach ($cabecalhos as $id => $linha) {
            $contingencias[] = Contingencia::deArray([
                'software' => Contingencia::SACTUS,
                'loja' => $linha['store'],
                'pdv' => $linha['pdv'],
                'cupom' => $linha['cupom'],
                'emitida_em' => $linha['fiscal_date'] ?? '',
                'codigo_motivo' => $linha['cod_motivo'] ?? '',
                'motivo' => $linha['motivo'] ?? '',
                'chave' => $linha['chave_acesso_nfce'] ?? '',
                'itens' => $itens[$id] ?? [],
            ]);
        }

        return [$contingencias, $total];
    }

    /**
     * Itens de todos os cupons de uma vez (o sistema antigo fazia uma consulta por cupom, até 1000 por clique).
     * Servidores com Sactus antigo podem não ter as colunas da reforma tributária: aí elas vêm vazias.
     *
     * @param  array<int, mixed>  $cupons
     * @return array<string, list<array<string, mixed>>> loja_pdv_cupom => itens
     */
    private function itensSactus(ConexaoComCliente $cliente, string $loja, array $cupons): array
    {
        $itens = [];
        $reforma = 's.cst_ibscbs, s.classtrib_ibs';

        foreach (array_chunk($cupons, 200) as $lote) {
            $marcadores = implode(',', array_fill(0, count($lote), '?'));
            $consulta = fn (string $colunasReforma): array => $cliente->conexao->select(
                "SELECT s.store, s.pdv, s.cupom, sa.chave_acesso_nfce,
                        s.sku, s.plu_id, s.plu_sdesc, s.unit, s.aliquota_cheia, s.reducao_base, s.taxid,
                        s.ncm, s.cest, s.cbenef, s.cfop, s.cst_icms, {$colunasReforma}
                   FROM {$cliente->tabela('sale_itens')} s
                   LEFT JOIN {$cliente->tabela('sale')} sa ON sa.cupom = s.cupom
                  WHERE s.store = ? AND s.cupom IN ({$marcadores})",
                [$loja, ...$lote],
            );

            try {
                $linhas = $consulta($reforma);
            } catch (QueryException $erro) {
                if (! str_contains($erro->getMessage(), 'Unknown column')) {
                    throw $erro;
                }

                $reforma = "'' AS cst_ibscbs, '' AS classtrib_ibs";
                $linhas = $consulta($reforma);
            }

            foreach ($linhas as $linha) {
                $itens["{$linha->store}_{$linha->pdv}_{$linha->cupom}"][] = [
                    'codigo_barras' => $linha->sku,
                    'id_produto' => $linha->plu_id,
                    'nome' => $linha->plu_sdesc,
                    'unidade' => $linha->unit,
                    'aliquota' => $linha->aliquota_cheia,
                    'reducao' => $linha->reducao_base,
                    'aliquota_reduzida' => $linha->taxid,
                    'ncm' => $linha->ncm,
                    'cest' => $linha->cest,
                    'cbenef' => $linha->cbenef,
                    'cfop' => $linha->cfop,
                    'cst' => $linha->cst_icms,
                    'cst_ibscbs' => $linha->cst_ibscbs,
                    'classtrib_ibs' => $linha->classtrib_ibs,
                    'chave' => $linha->chave_acesso_nfce,
                ];
            }
        }

        return $itens;
    }

    /**
     * Uma por venda (loja + PDV + cupom) e só as com loja, PDV e cupom numéricos, como o sistema antigo.
     *
     * @param  list<Contingencia>  $contingencias
     * @return list<Contingencia>
     */
    private function semRepetidas(array $contingencias): array
    {
        $unicas = [];

        foreach ($contingencias as $contingencia) {
            if ((int) $contingencia->loja > 0 && (int) $contingencia->pdv > 0 && (int) $contingencia->cupom > 0) {
                $unicas[$contingencia->identificador()] ??= $contingencia;
            }
        }

        return array_values($unicas);
    }

    /**
     * As já autorizadas na Sefaz vão para a lista "Autorizadas". Saem das pendentes as autorizadas
     * com referência do mês passado para cá (mesma janela do sistema antigo).
     *
     * @param  list<Contingencia>  $contingencias
     * @return array{0: list<Contingencia>, 1: list<Autorizada>}
     */
    private function separarAutorizadas(int $clienteId, array $contingencias): array
    {
        $porChave = [];

        foreach ($contingencias as $contingencia) {
            if ($contingencia->chaveNfce() !== '') {
                $porChave[$contingencia->chaveNfce()] = $contingencia;
            }
        }

        $inicioJanela = now()->subMonthNoOverflow()->startOfMonth()->toDateString();
        $fimJanela = now()->endOfMonth()->toDateString();
        $autorizadas = [];
        $sairDasPendentes = [];

        foreach (array_chunk(array_keys($porChave), 500) as $lote) {
            $linhas = DB::table('history_contingencia_auth')
                ->where('fk_c_cliente', $clienteId)
                ->where('autorizada', 'S')
                ->whereIn('chave', array_map('strval', $lote))
                ->orderByDesc('data_consulta_sefaz_bot')
                ->get(['chave', 'referencia', 'data_consulta_sefaz_bot']);

            foreach ($linhas as $linha) {
                $autorizadas[] = new Autorizada(
                    chave: $linha->chave,
                    referencia: (string) $linha->referencia,
                    consultadaEm: (string) $linha->data_consulta_sefaz_bot,
                    contingencia: $porChave[$linha->chave],
                );

                if ($linha->referencia !== null && $linha->referencia >= $inicioJanela && $linha->referencia <= $fimJanela) {
                    $sairDasPendentes[$linha->chave] = true;
                }
            }
        }

        $pendentes = array_values(array_filter(
            $contingencias,
            fn (Contingencia $c): bool => ! isset($sairDasPendentes[$c->chaveNfce()]),
        ));

        return [$pendentes, $autorizadas];
    }
}
