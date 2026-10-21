<?php

namespace App\Contingencias\Cliente;

use RuntimeException;

/**
 * Não deu para ler o servidor do cliente. A mensagem é para a tela (sem IP, porta ou senha);
 * o detalhe técnico vai para o log.
 */
class FalhaConexaoCliente extends RuntimeException
{
    public function __construct(
        public readonly string $codigo,
        string $mensagem,
        public readonly string $detalhe = '',
    ) {
        parent::__construct($mensagem);
    }

    public static function semCadastroNoHelpDesk(): self
    {
        return new self('ERRO_SEM_CADASTRO', 'Este cliente não tem a informação técnica (servidor) cadastrada no Help Desk.');
    }

    public static function cadastroIncompleto(): self
    {
        return new self('ERRO_PARAMETROS', 'O cadastro do servidor deste cliente no Help Desk está incompleto (IP, banco ou senha).');
    }

    public static function servidorNaoResponde(string $detalhe): self
    {
        return new self('ERRO_HOST', 'O servidor do cliente não respondeu (desligado, sem internet ou VPN fora do ar).', $detalhe);
    }

    public static function acessoNegado(string $detalhe): self
    {
        return new self('ERRO_MYSQL', 'Não foi possível entrar no MySQL do servidor do cliente (usuário, senha ou banco).', $detalhe);
    }

    public static function semBancoActus(string $detalhe): self
    {
        return new self('ERRO_SEM_ACTUS', 'O banco do Sactus (actus) não foi encontrado no servidor do cliente.', $detalhe);
    }
}
