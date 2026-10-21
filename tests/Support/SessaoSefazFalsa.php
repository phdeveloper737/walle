<?php

namespace Tests\Support;

use App\Sefaz\SessaoSefaz;

/**
 * Sefaz de mentira para os testes: responde cada chave com o cStat combinado
 * (ou 217, NF-e inexistente, para as chaves não combinadas) e anota o que foi consultado.
 */
class SessaoSefazFalsa implements SessaoSefaz
{
    /** @var list<string> */
    public array $consultadas = [];

    public bool $fechada = false;

    /** @param array<string, string> $cStatPorChave chave => cStat */
    public function __construct(private readonly array $cStatPorChave = []) {}

    public function enviar(string $envelope): array
    {
        preg_match('/<chNFe>(\d{44})<\/chNFe>/', $envelope, $achou);
        $chave = $achou[1] ?? '';
        $this->consultadas[] = $chave;
        $cStat = $this->cStatPorChave[$chave] ?? '217';

        return ['http' => 200, 'corpo' => self::resposta($chave, $cStat), 'erro' => null];
    }

    public function fechar(): void
    {
        $this->fechada = true;
    }

    /** Resposta SOAP no formato da Sefaz GO (retConsSitNFe dentro de nfeResultMsg). */
    public static function resposta(string $chave, string $cStat, bool $eventoCancelamento = false): string
    {
        $motivo = match ($cStat) {
            '100' => 'Autorizado o uso da NF-e',
            '217' => 'Rejeição: NF-e não consta na base de dados da SEFAZ',
            '656' => 'Rejeição: Consumo Indevido',
            default => 'Outro motivo',
        };

        $protocolo = $cStat === '100'
            ? '<protNFe versao="4.00"><infProt><tpAmb>1</tpAmb><chNFe>'.$chave.'</chNFe><dhRecbto>2026-10-20T10:00:00-03:00</dhRecbto><nProt>152260000000001</nProt><cStat>100</cStat></infProt></protNFe>'
            : '';

        $evento = $eventoCancelamento
            ? '<procEventoNFe versao="1.00"><evento><infEvento><tpEvento>110111</tpEvento></infEvento></evento></procEventoNFe>'
            : '';

        return '<?xml version="1.0" encoding="utf-8"?><soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope"><soap:Body>'
            .'<nfeResultMsg xmlns="http://www.portalfiscal.inf.br/nfe/wsdl/NFeConsultaProtocolo4">'
            .'<retConsSitNFe xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00"><tpAmb>1</tpAmb><cStat>'.$cStat.'</cStat><xMotivo>'.$motivo.'</xMotivo>'
            .'<cUF>52</cUF><chNFe>'.$chave.'</chNFe>'.$protocolo.$evento.'</retConsSitNFe>'
            .'</nfeResultMsg></soap:Body></soap:Envelope>';
    }
}
