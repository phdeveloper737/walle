<?php

namespace App\Conversor;

use RuntimeException;

/**
 * O .pfx não pôde ser convertido. A mensagem vai para a tela; o motivo, para a auditoria.
 * Nunca carrega o conteúdo do arquivo, a senha nem o erro bruto do OpenSSL.
 */
final class CertificadoInvalido extends RuntimeException
{
    private function __construct(string $mensagem, public readonly string $motivo)
    {
        parent::__construct($mensagem);
    }

    public static function vazio(): self
    {
        return new self('Escolha o arquivo .pfx do certificado.', 'arquivo_invalido');
    }

    public static function grandeDemais(int $limite): self
    {
        return new self('O arquivo é maior que '.intdiv($limite, 1024).' KB, o que não é um certificado A1 comum. Confira se escolheu o .pfx certo.', 'arquivo_invalido');
    }

    public static function naoEhPfx(): self
    {
        return new self('O arquivo não é um certificado .pfx válido. Escolha o .pfx (ou .p12) exportado do certificado A1.', 'arquivo_invalido');
    }

    public static function senhaIncorreta(): self
    {
        return new self('Senha do certificado incorreta. Confira a senha do .pfx e tente de novo.', 'senha_incorreta');
    }

    public static function semChave(): self
    {
        return new self('O .pfx abriu, mas não traz a chave privada. Exporte o certificado de novo incluindo a chave.', 'sem_chave');
    }
}
