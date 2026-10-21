<?php

namespace App\Sefaz;

use Closure;

/**
 * Consulta a situação de NF-e/NFC-e na Sefaz GO (NFeConsultaProtocolo 4.00, SOAP 1.2).
 * Substitui o consulta.nfce.php do sistema antigo.
 *
 * Proteção do certificado: ao receber 656 (consumo indevido) para na hora e não consulta mais nada.
 */
class ConsultaSituacao
{
    /** @var Closure(): SessaoSefaz */
    private Closure $abrirSessao;

    /** @param (Closure(): SessaoSefaz)|null $abrirSessao Nos testes, uma sessão falsa com respostas gravadas. */
    public function __construct(?Closure $abrirSessao = null)
    {
        $this->abrirSessao = $abrirSessao ?? fn (): SessaoSefaz => new SessaoSefazComCertificado(
            Certificado::configurado(),
            (int) config('services.sefaz.ambiente') === 1 ? config('services.sefaz.url_producao') : config('services.sefaz.url_homologacao'),
            (int) config('services.sefaz.timeout', 20),
        );
    }

    /**
     * @param  list<string>  $chaves
     * @param  (Closure(ResultadoConsulta): void)|null  $aoConsultar  chamado depois de cada chave (o robô usa para gravar aos poucos)
     * @return list<ResultadoConsulta>
     */
    public function consultar(array $chaves, ?Closure $aoConsultar = null, int $pausaMs = 250): array
    {
        $uf = (string) config('services.sefaz.uf', '52');
        $resultados = [];
        $sessao = null;
        $bloqueado = false;

        try {
            foreach ($chaves as $i => $valor) {
                $chave = ChaveDeAcesso::de($valor);

                $resultado = match (true) {
                    ($problema = $chave->problema($uf)) !== null => ResultadoConsulta::erro($chave->chave, $problema),
                    $bloqueado => ResultadoConsulta::erro($chave->chave, 'Não consultada: a Sefaz acusou consumo indevido nesta rodada'),
                    default => $this->consultarUma($sessao ??= ($this->abrirSessao)(), $chave->chave, $i > 0 ? $pausaMs : 0),
                };

                $bloqueado = $bloqueado || $resultado->consumoIndevido();
                $resultados[] = $resultado;

                if ($aoConsultar !== null) {
                    $aoConsultar($resultado);
                }
            }
        } finally {
            $sessao?->fechar();
        }

        return $resultados;
    }

    private function consultarUma(SessaoSefaz $sessao, string $chave, int $pausaMs): ResultadoConsulta
    {
        if ($pausaMs > 0) {
            usleep($pausaMs * 1000);
        }

        $resposta = $sessao->enviar(self::envelope($chave, (int) config('services.sefaz.ambiente', 1)));

        if ($resposta['corpo'] === null) {
            return ResultadoConsulta::erro($chave, 'Falha de comunicação: '.$resposta['erro']);
        }

        if ($resposta['http'] !== 200) {
            return ResultadoConsulta::erro($chave, "HTTP {$resposta['http']}");
        }

        return self::interpretar($chave, $resposta['corpo']);
    }

    public static function envelope(string $chave, int $ambiente): string
    {
        $consulta = '<consSitNFe xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00">'
            ."<tpAmb>{$ambiente}</tpAmb><xServ>CONSULTAR</xServ><chNFe>{$chave}</chNFe>"
            .'</consSitNFe>';

        return '<?xml version="1.0" encoding="utf-8"?>'
            .'<soap12:Envelope xmlns:soap12="http://www.w3.org/2003/05/soap-envelope"><soap12:Body>'
            .'<nfeDadosMsg xmlns="http://www.portalfiscal.inf.br/nfe/wsdl/NFeConsultaProtocolo4">'.$consulta.'</nfeDadosMsg>'
            .'</soap12:Body></soap12:Envelope>';
    }

    /** Lê o retConsSitNFe (cStat, motivo, protocolo e evento de cancelamento 110111). */
    public static function interpretar(string $chave, string $soap): ResultadoConsulta
    {
        // Sem namespaces e prefixos, para o XPath simples funcionar
        $limpo = preg_replace('/xmlns(:[a-zA-Z0-9]+)?="[^"]*"/', '', $soap) ?? '';
        $limpo = preg_replace('/(<\/?)[a-zA-Z0-9]+:/', '$1', $limpo) ?? '';

        $anterior = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($limpo);
        libxml_use_internal_errors($anterior);

        if ($xml === false) {
            return ResultadoConsulta::erro($chave, 'Resposta ilegível da Sefaz');
        }

        $ret = $xml->xpath('//retConsSitNFe')[0] ?? null;

        if ($ret === null) {
            return ResultadoConsulta::erro($chave, 'retConsSitNFe ausente na resposta');
        }

        $cancelada = isset($ret->retCancNFe);

        foreach ($ret->xpath('//procEventoNFe//tpEvento') ?: [] as $evento) {
            $cancelada = $cancelada || (string) $evento === '110111';
        }

        $protocolo = $ret->protNFe->infProt ?? null;

        return new ResultadoConsulta(
            chave: $chave,
            situacao: SituacaoNfce::doCStat((string) $ret->cStat, $cancelada),
            cStat: (string) $ret->cStat,
            motivo: (string) $ret->xMotivo,
            protocolo: $protocolo !== null ? ((string) $protocolo->nProt ?: null) : null,
            recebidaEm: $protocolo !== null ? ((string) $protocolo->dhRecbto ?: null) : null,
        );
    }
}
