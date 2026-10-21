<?php

namespace App\Contingencias\Cliente;

use Illuminate\Database\Connection;

/** Conexão aberta com o MySQL do cliente e o banco do Sactus (actus) já resolvido. */
final readonly class ConexaoComCliente
{
    public function __construct(
        public Connection $conexao,
        public string $bancoActus,
        /** Nos testes a "conexão do cliente" é a do próprio banco de testes e não pode ser fechada. */
        private bool $fecharAoTerminar = true,
    ) {}

    /** Nome da tabela com o banco do Sactus na frente, protegido com crase. */
    public function tabela(string $tabela): string
    {
        return '`'.str_replace('`', '', $this->bancoActus).'`.`'.$tabela.'`';
    }

    public function desconectar(): void
    {
        if ($this->fecharAoTerminar) {
            $this->conexao->disconnect();
        }
    }
}
