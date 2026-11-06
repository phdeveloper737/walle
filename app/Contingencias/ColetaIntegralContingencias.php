<?php

namespace App\Contingencias;

use App\Contingencias\Cliente\ConectorDeCliente;
use App\Contingencias\Cliente\FalhaConexaoCliente;

/**
 * Leitura integral do set.history.cont.php: sem cache, limite da tela ou filtro das autorizadas.
 * Apenas cabeçalhos são necessários para o histórico; itens e alterações de CRT ficam fora da coleta.
 * Ambos os softwares são coletados: o legado força tips_pdv = 'A' antes dos ramos (linha 292).
 * Os ramos Versatus (299-346) e Linux/Sactus (353-511) do legado são independentes: se a conexão com o
 * cliente falha, o Versatus (lido ao vivo do 220 pela LeituraVersatus, mesmo vazio) ainda vai em FalhaSactusComVersatus.
 * Só FalhaConexaoCliente na conexão/leitura do Sactus aciona isso; cadastro incompleto (antes) e erro inesperado
 * (ex. QueryException depois de conectar) seguem como falha comum, sem gravação parcial.
 * Cliente só Versatus (servidor_multiempresa = 'V'): não conecta no servidor dele, só lê o Versatus do 220.
 * Leitura do 220 com falha: com Sactus coletado vai em FalhaVersatusNo220 (o robô preserva o Versatus do walle).
 */
class ColetaIntegralContingencias
{
    public function __construct(private readonly ConectorDeCliente $conector, private readonly LeituraVersatus $leituraVersatus) {}

    /**
     * @param  array<string, mixed>  $servidor
     *
     * @throws FalhaSactusComVersatus Sactus fora do ar, mas o Versatus do 220 foi lido (mesmo vazio)
     * @throws FalhaVersatusNo220 Sactus coletado, mas a leitura do Versatus no 220 falhou
     * @throws FalhaLeituraVersatus cliente só Versatus e a leitura no 220 falhou
     */
    public function coletar(int $clienteId, array $servidor): ResultadoContingencias
    {
        if (LeituraVersatus::somenteVersatus($servidor)) {
            return new ResultadoContingencias($this->leituraVersatus->cabecalhos($clienteId), [], 0, null, now()->format('H:i:s'));
        }

        $loja = trim((string) ($servidor['loja'] ?? ''));

        if ((int) $loja <= 0) {
            throw FalhaConexaoCliente::cadastroIncompleto();
        }

        try {
            $sactus = $this->coletarSactus($clienteId, $servidor, $loja);
        } catch (FalhaConexaoCliente $falha) {
            try {
                $versatus = $this->leituraVersatus->cabecalhos($clienteId);
            } catch (FalhaLeituraVersatus) {
                throw $falha;
            }

            throw new FalhaSactusComVersatus($falha, $versatus);
        }

        try {
            $versatus = $this->leituraVersatus->cabecalhos($clienteId);
        } catch (FalhaLeituraVersatus $falha) {
            throw new FalhaVersatusNo220($falha, $sactus);
        }

        return new ResultadoContingencias([...$sactus, ...$versatus], [], count($sactus), null, now()->format('H:i:s'));
    }

    /**
     * @param  array<string, mixed>  $servidor
     * @return list<Contingencia>
     */
    private function coletarSactus(int $clienteId, array $servidor, string $loja): array
    {
        $conexao = $this->conector->conectar($clienteId, $servidor);

        try {
            $linhas = $conexao->conexao->select('SELECT * FROM '.$conexao->tabela('contingencias_pdv').' WHERE store = ?', [$loja]);

            return array_map(fn (object $linha): Contingencia => Contingencia::deArray([
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
    }
}
