<?php

namespace App\Sactus\Ssh;

/** Abre e autentica uma sessão SSH. Nos testes entra um servidor falso no lugar. */
interface ConectorSsh
{
    /** @throws FalhaSsh com a mesma mensagem do sistema antigo (handshake ou autenticação) */
    public function abrir(AlvoSsh $alvo): SessaoSsh;
}
