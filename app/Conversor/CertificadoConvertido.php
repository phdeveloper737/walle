<?php

namespace App\Conversor;

use LogicException;
use SensitiveParameter;

/**
 * Resultado da conversão, só em memória. Não pode ser serializado (sessão, cache, fila)
 * e não mostra a chave em dump nem em print_r.
 */
final readonly class CertificadoConvertido
{
    /** @param list<string> $cadeia PEMs dos certificados intermediários que vieram no .pfx */
    public function __construct(
        public string $certificado,
        #[SensitiveParameter] public string $chave,
        public array $cadeia,
        public string $titular,
        public ?string $validoAte,
    ) {}

    /** Certificado, chave e cadeia num PEM só (o "PEM completo" do 220, usado em mTLS, cURL e Sefaz). */
    public function pem(): string
    {
        $partes = [rtrim($this->certificado), rtrim($this->chave)];

        foreach ($this->cadeia as $intermediario) {
            $partes[] = rtrim($intermediario);
        }

        return implode("\n", $partes)."\n";
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['titular' => $this->titular, 'validoAte' => $this->validoAte, 'chave' => '[oculta]', 'certificado' => '[omitido]'];
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new LogicException('O certificado convertido não pode ser serializado (sessão, cache, fila ou log).');
    }

    /** @param array<string, mixed> $dados */
    public function __unserialize(array $dados): void
    {
        throw new LogicException('O certificado convertido não pode ser serializado (sessão, cache, fila ou log).');
    }
}
