<?php

namespace App\Contingencias;

use App\Contingencias\Cliente\ConectorDeCliente;
use App\Contingencias\Cliente\FalhaConexaoCliente;
use Illuminate\Support\Facades\DB;

/**
 * Leitura integral do set.history.cont.php: sem cache, limite da tela ou filtro das autorizadas.
 * Apenas cabeçalhos são necessários para o histórico; itens e alterações de CRT ficam fora da coleta.
 * Ambos os softwares são coletados: o legado força tips_pdv = 'A' antes dos ramos (linha 292).
 */
class ColetaIntegralContingencias
{
    public function __construct(private readonly ConectorDeCliente $conector) {}

    /** @param array<string, mixed> $servidor */
    public function coletar(int $clienteId, array $servidor): ResultadoContingencias
    {
        $loja = trim((string) ($servidor['loja'] ?? ''));

        if ((int) $loja <= 0) {
            throw FalhaConexaoCliente::cadastroIncompleto();
        }

        $conexao = $this->conector->conectar($clienteId, $servidor);

        try {
            $linhas = $conexao->conexao->select('SELECT * FROM '.$conexao->tabela('contingencias_pdv').' WHERE store = ?', [$loja]);
            $sactus = array_map(fn (object $linha): Contingencia => Contingencia::deArray([
                'software' => Contingencia::SACTUS,
                'loja' => $linha->store,
                'pdv' => $linha->pdv,
                'cupom' => $linha->cupom,
                'emitida_em' => $linha->fiscal_date,
                'codigo_motivo' => $linha->cod_motivo ?? '',
                'motivo' => $linha->motivo ?? '',
                'chave' => $linha->chave_acesso_nfce ?? '',
            ]), $linhas);
        } finally {
            $conexao->desconectar();
        }

        $versatus = DB::table('contingencias_versatus_venda')
            ->where('fk_contingencias_versatus', $clienteId)
            ->where('motivo', 'not like', '%Nao Enviada para Sefaz%')
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

        return new ResultadoContingencias([...$sactus, ...$versatus], [], count($sactus), null, now()->format('H:i:s'));
    }
}
