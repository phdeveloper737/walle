<?php

namespace App\Sefaz;

use RuntimeException;

/**
 * Certificado A1 (PFX) da JM Sistemas usado nas consultas à Sefaz.
 *
 * Fica FORA da pasta pública (storage/app/private/certificados) e a senha só no .env.
 * No sistema antigo o teste.pfx estava dentro do site, baixável por qualquer um da rede.
 */
class Certificado
{
    public function __construct(
        private readonly string $caminho,
        #[\SensitiveParameter] private readonly string $senha,
    ) {}

    public static function configurado(): self
    {
        return new self(
            (string) config('services.sefaz.certificado'),
            (string) config('services.sefaz.certificado_senha'),
        );
    }

    /**
     * Abre o PFX e grava certificado e chave em arquivos temporários 0600 (o cURL exige caminho).
     * Quem chama apaga os arquivos ao terminar.
     *
     * @return array{cert: string, chave: string, titular: string, validade: string|null}
     */
    public function materializar(): array
    {
        $dados = $this->ler();

        $cert = (string) tempnam(sys_get_temp_dir(), 'walle_crt_');
        $chave = (string) tempnam(sys_get_temp_dir(), 'walle_key_');
        chmod($cert, 0600);
        chmod($chave, 0600);
        file_put_contents($cert, $dados['cert']);
        file_put_contents($chave, $dados['pkey']);

        return ['cert' => $cert, 'chave' => $chave, 'titular' => $dados['titular'], 'validade' => $dados['validade']];
    }

    /** @return array{titular: string, validade: string|null, dias_restantes: int|null} */
    public function informacoes(): array
    {
        $dados = $this->ler();

        return [
            'titular' => $dados['titular'],
            'validade' => $dados['validade'],
            'dias_restantes' => $dados['validade'] !== null
                ? (int) floor((strtotime($dados['validade']) - time()) / 86400)
                : null,
        ];
    }

    /** @return array{cert: string, pkey: string, titular: string, validade: string|null} */
    private function ler(): array
    {
        if (! is_readable($this->caminho)) {
            throw new RuntimeException("Certificado não encontrado em {$this->caminho}");
        }

        $certs = [];

        if (! openssl_pkcs12_read((string) file_get_contents($this->caminho), $certs, $this->senha)) {
            throw new RuntimeException('Não foi possível abrir o certificado (senha errada?): '.openssl_error_string());
        }

        $x509 = openssl_x509_parse($certs['cert']) ?: [];
        $validade = (int) ($x509['validTo_time_t'] ?? 0);

        if ($validade > 0 && $validade < time()) {
            throw new RuntimeException('Certificado vencido em '.date('d/m/Y', $validade));
        }

        return [
            'cert' => $certs['cert'],
            'pkey' => $certs['pkey'],
            'titular' => (string) ($x509['subject']['CN'] ?? ''),
            'validade' => $validade > 0 ? date('Y-m-d', $validade) : null,
        ];
    }
}
