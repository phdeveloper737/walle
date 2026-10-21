<?php

namespace App\Sactus\Ssh;

use phpseclib3\Net\SSH2;
use Throwable;

/**
 * SSH de verdade, com o phpseclib (PHP puro, sem a extensão ssh2 do antigo).
 * O phpseclib já negocia os algoritmos antigos (diffie-hellman-group1, ssh-dss, 3des-cbc) que o antigo
 * tentava num segundo handshake "legado" para os CentOS velhos.
 */
class ConectorPhpseclib implements ConectorSsh
{
    public function abrir(AlvoSsh $alvo): SessaoSsh
    {
        $ssh = new SSH2($alvo->host, $alvo->porta, (int) config('walle.sactus.ssh.conexao_segundos', 10));

        // Falha de conexão ou de handshake vira exceção no phpseclib; login() false é senha recusada
        try {
            $entrou = $ssh->login($alvo->usuario, $alvo->senha);
        } catch (Throwable) {
            throw FalhaSsh::handshake();
        }

        if (! $entrou) {
            $ssh->disconnect();

            throw FalhaSsh::autenticacao();
        }

        return new SessaoPhpseclib($ssh);
    }
}
