<?php

namespace App\Ferramentas;

/**
 * O que uma execução manual de SQL no cliente devolveu (mensagem pronta para a tela, sem host, usuário, senha nem o SQL).
 *
 * tipo: 'leitura' (SELECT/SHOW/DESCRIBE/EXPLAIN, com colunas e linhas), 'escrita' (com afetadas) ou '' quando
 * nada foi enviado ao cliente. pedeSenha: a confirmação de senha não existe ou expirou; a tela pede a senha de novo.
 */
final readonly class ResultadoQuery
{
    /**
     * @param  list<string>  $colunas
     * @param  list<array<string, string|null>>  $linhas
     */
    public function __construct(
        public bool $ok,
        public string $mensagem,
        public array $colunas = [],
        public array $linhas = [],
        public ?int $afetadas = null,
        public string $tipo = '',
        public bool $truncado = false,
        public bool $pedeSenha = false,
    ) {}

    public static function recusa(string $mensagem, bool $pedeSenha = false): self
    {
        return new self(false, $mensagem, pedeSenha: $pedeSenha);
    }

    public static function falha(string $mensagem, string $tipo = ''): self
    {
        return new self(false, $mensagem, tipo: $tipo);
    }
}
