<?php

namespace App\Sactus\Ssh;

/** Resultado de um comando no servidor do cliente. rc null = não terminou dentro do prazo (ou o canal nem abriu). */
final readonly class ResultadoComando
{
    public function __construct(
        public ?int $rc,
        public string $saida = '',
        public string $erro = '',
    ) {}

    public function ok(): bool
    {
        return $this->rc === 0;
    }

    /** " | erro" com os primeiros N caracteres do stderr, ou vazio (para montar as mensagens como o antigo). */
    public function complemento(int $max = 200): string
    {
        return $this->erro !== '' ? ' | '.mb_substr($this->erro, 0, $max) : '';
    }
}
