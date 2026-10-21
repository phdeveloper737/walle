<?php

namespace App\Sefaz;

use CurlHandle;

/**
 * Sessão real com a Sefaz: TLS mútuo com o certificado A1. Um único handle cURL para a sessão
 * inteira mantém a conexão TLS aberta entre as chaves (como no sistema antigo).
 */
class SessaoSefazComCertificado implements SessaoSefaz
{
    private CurlHandle $curl;

    /** @var array{cert: string, chave: string, titular: string, validade: string|null} */
    private array $arquivos;

    public function __construct(Certificado $certificado, string $url, int $timeout)
    {
        $this->arquivos = $certificado->materializar();
        $this->curl = curl_init();

        curl_setopt_array($this->curl, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSLCERT => $this->arquivos['cert'],
            CURLOPT_SSLKEY => $this->arquivos['chave'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => ['Content-Type: application/soap+xml; charset=utf-8'],
        ]);
    }

    public function enviar(string $envelope): array
    {
        curl_setopt($this->curl, CURLOPT_POSTFIELDS, $envelope);

        $corpo = curl_exec($this->curl);

        return [
            'http' => (int) curl_getinfo($this->curl, CURLINFO_HTTP_CODE),
            'corpo' => is_string($corpo) ? $corpo : null,
            'erro' => $corpo === false ? curl_error($this->curl) : null,
        ];
    }

    public function fechar(): void
    {
        @unlink($this->arquivos['cert']);
        @unlink($this->arquivos['chave']);
    }

    public function __destruct()
    {
        $this->fechar();
    }
}
