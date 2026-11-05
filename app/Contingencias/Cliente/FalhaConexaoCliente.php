<?php

namespace App\Contingencias\Cliente;

use RuntimeException;

/**
 * Não deu para ler o servidor do cliente. A mensagem é para a tela (sem IP, porta ou senha);
 * o detalhe técnico vai para o log. A tentativa (host, porta, etapa, erro do socket...) é só para o admin
 * e nunca contém a senha.
 */
class FalhaConexaoCliente extends RuntimeException
{
    /** @param array<string, mixed> $tentativa dados técnicos da tentativa de conexão, sem senha */
    public function __construct(
        public readonly string $codigo,
        string $mensagem,
        public readonly string $detalhe = '',
        public readonly array $tentativa = [],
    ) {
        parent::__construct($mensagem);
    }

    public static function semCadastroNoHelpDesk(): self
    {
        return new self('ERRO_SEM_CADASTRO', 'Este cliente não tem a informação técnica (servidor) cadastrada no Help Desk.', '', ['etapa' => 'cadastro']);
    }

    /** @param array<string, mixed> $tentativa */
    public static function cadastroIncompleto(array $tentativa = []): self
    {
        return new self('ERRO_PARAMETROS', 'O cadastro do servidor deste cliente no Help Desk está incompleto (IP, banco ou senha).', '', $tentativa);
    }

    /** @param array<string, mixed> $tentativa */
    public static function servidorNaoResponde(string $detalhe, array $tentativa = []): self
    {
        return new self('ERRO_HOST', 'O servidor do cliente não respondeu (desligado, sem internet ou VPN fora do ar).', $detalhe, $tentativa);
    }

    /** @param array<string, mixed> $tentativa */
    public static function acessoNegado(string $detalhe, array $tentativa = []): self
    {
        return new self('ERRO_MYSQL', 'Não foi possível entrar no MySQL do servidor do cliente (usuário, senha ou banco).', $detalhe, $tentativa);
    }

    /** @param array<string, mixed> $tentativa */
    public static function semBancoActus(string $detalhe, array $tentativa = []): self
    {
        return new self('ERRO_SEM_ACTUS', 'O banco do Sactus (actus) não foi encontrado no servidor do cliente.', $detalhe, $tentativa);
    }
}
