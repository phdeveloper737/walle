<?php

namespace App\Conversor;

use SensitiveParameter;

/**
 * Converte o certificado A1 (.pfx/PKCS#12) em PEM, inteiramente em memória.
 * Substitui o menu/conversor.php do sistema antigo.
 *
 * Nada é gravado: nem arquivo (o openssl_pkcs12_read trabalha sobre a string), nem log, nem cache.
 * A fila de erros do OpenSSL é esvaziada antes e depois, sem ser repassada a ninguém.
 */
class ConversorCertificado
{
    /**
     * Limite do .pfx: um A1 com a cadeia tem uns 3 a 8 KB. Com o base64 e o resto do pedido do Livewire,
     * o corpo fica em uns 14,5 KB (medido no teste do conversor), abaixo dos 16 KB que o PHP-FPM guarda em memória
     * (SAPI_POST_BLOCK_SIZE) antes de usar arquivo temporário. O nginx tem buffer padrão de só 8 KB; por isso o
     * docker/nginx/default.conf define client_body_buffer_size 32k e fastcgi_max_temp_file_size 0.
     */
    public const TAMANHO_MAXIMO = 10 * 1024;

    /**
     * Limite da senha do .pfx, em caracteres (maxlength e Alpine na tela, mb_strlen no servidor). Sem ele, uma senha
     * longa colada por engano passaria o corpo do pedido dos 16 KB e levaria PFX e senha ao temporário do PHP/nginx
     * antes de qualquer validação. No pior caso (128 caracteres de 6 bytes no JSON) o corpo segue abaixo de 15 KB.
     */
    public const SENHA_MAXIMA = 128;

    /** @throws CertificadoInvalido */
    public function converter(#[SensitiveParameter] string $conteudoPfx, #[SensitiveParameter] string $senha): CertificadoConvertido
    {
        if ($conteudoPfx === '') {
            throw CertificadoInvalido::vazio();
        }

        if (strlen($conteudoPfx) > self::TAMANHO_MAXIMO) {
            throw CertificadoInvalido::grandeDemais(self::TAMANHO_MAXIMO);
        }

        self::esvaziarErrosOpenSsl();
        $partes = [];
        $abriu = openssl_pkcs12_read($conteudoPfx, $partes, $senha);
        $erros = self::esvaziarErrosOpenSsl();

        if (! $abriu) {
            throw str_contains($erros, 'mac verify') || str_contains($erros, 'invalid password')
                ? CertificadoInvalido::senhaIncorreta()
                : CertificadoInvalido::naoEhPfx();
        }

        $certificado = is_string($partes['cert'] ?? null) ? $partes['cert'] : '';
        $chave = is_string($partes['pkey'] ?? null) ? $partes['pkey'] : '';

        if ($certificado === '') {
            throw CertificadoInvalido::naoEhPfx();
        }

        if ($chave === '') {
            throw CertificadoInvalido::semChave();
        }

        $cadeia = array_values(array_filter((array) ($partes['extracerts'] ?? []), is_string(...)));
        $dados = openssl_x509_parse($certificado);
        $titular = is_array($dados) && is_string($dados['subject']['CN'] ?? null) ? $dados['subject']['CN'] : '';
        $validoAte = is_array($dados) && is_int($dados['validTo_time_t'] ?? null) ? date('d/m/Y', $dados['validTo_time_t']) : null;

        return new CertificadoConvertido($certificado, $chave, $cadeia, $titular, $validoAte);
    }

    /** Lê e descarta a fila de erros do OpenSSL (global no processo), devolvendo o texto em minúsculas para classificar a falha. */
    private static function esvaziarErrosOpenSsl(): string
    {
        $erros = '';

        while (($erro = openssl_error_string()) !== false) {
            $erros .= strtolower($erro).' ';
        }

        return $erros;
    }
}
