<?php

namespace App\Contingencias;

/** O que uma ação manual nas contingências fez no servidor do cliente (mensagem pronta para a tela, sem segredos). */
final readonly class ResultadoAcao
{
    /** @param list<string> $falhas */
    public function __construct(
        public bool $ok,
        public string $mensagem,
        public int $enviados = 0,
        public array $falhas = [],
    ) {}

    public static function falha(string $mensagem): self
    {
        return new self(false, $mensagem);
    }
}
