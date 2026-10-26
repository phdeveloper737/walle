<?php

namespace App\Support\HelpDesk;

/**
 * O que o usuário da conexão "help" pode fazer no Help Desk (PermissoesHelpDesk).
 *
 * Só guarda nomes de privilégio e uma mensagem de erro já sem host nem SQL: nunca a linha bruta do
 * SHOW GRANTS (no MySQL 5.x ela traz o hash da senha). Vai para o cache como array (toArray/fromArray).
 */
final readonly class ResultadoPermissoes
{
    /** @param  list<string>  $privilegiosDeEscrita  nomes dos privilégios que permitem gravar (ex.: INSERT) */
    public function __construct(
        public bool $somenteLeitura,
        public array $privilegiosDeEscrita = [],
        public ?string $erro = null,
    ) {}

    /** Não deu para conferir: na dúvida, conta como podendo gravar. */
    public static function falha(string $erro): self
    {
        return new self(false, [], $erro);
    }

    /** @return array{somente_leitura: bool, privilegios_de_escrita: list<string>, erro: string|null} */
    public function toArray(): array
    {
        return [
            'somente_leitura' => $this->somenteLeitura,
            'privilegios_de_escrita' => $this->privilegiosDeEscrita,
            'erro' => $this->erro,
        ];
    }

    /** @param  array<mixed>  $dados  o que saiu do toArray() */
    public static function fromArray(array $dados): self
    {
        $privilegios = array_values(array_filter(
            (array) ($dados['privilegios_de_escrita'] ?? []),
            fn (mixed $privilegio): bool => is_string($privilegio) && preg_match('/^[A-Z_]+( [A-Z_]+)*$/', $privilegio) === 1,
        ));
        $erro = is_string($dados['erro'] ?? null) ? $dados['erro'] : null;

        return new self(
            ($dados['somente_leitura'] ?? false) === true && $privilegios === [] && $erro === null,
            $privilegios,
            $erro,
        );
    }
}
