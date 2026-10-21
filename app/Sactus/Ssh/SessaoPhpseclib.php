<?php

namespace App\Sactus\Ssh;

use phpseclib3\Net\SSH2;
use Throwable;

class SessaoPhpseclib implements SessaoSsh
{
    /** O código de retorno vem num marcador no fim da saída, como no antigo (funciona em qualquer servidor). */
    private const MARCADOR = '__RC__';

    /** Depois de um comando que passou do prazo a sessão é encerrada (ver executar). */
    private bool $encerrada = false;

    public function __construct(private readonly SSH2 $ssh)
    {
        // stdout e stderr separados
        $this->ssh->enableQuietMode();
    }

    public function executar(string $comando, int $timeoutSegundos): ResultadoComando
    {
        if ($this->encerrada) {
            return new ResultadoComando(null, '', 'sessao encerrada: um comando anterior passou do prazo');
        }

        $this->ssh->setTimeout($timeoutSegundos);

        try {
            $saida = (string) $this->ssh->exec($comando.'; echo "'.self::MARCADOR.'$?"');
        } catch (Throwable $e) {
            return new ResultadoComando(null, '', 'falha ao abrir o canal de execucao: '.$e->getMessage());
        }

        $erro = trim((string) preg_replace('~\s+~', ' ', (string) $this->ssh->getStdError()));

        if ($this->ssh->isTimeout()) {
            // Passou do prazo: encerra a sessão. O reset() do phpseclib esperaria o comando remoto terminar
            // (um yum de 30 min seguraria o robô os 30 min); o disconnect não espera.
            $this->ssh->disconnect();
            $this->encerrada = true;

            return new ResultadoComando(null, '', 'passou do prazo de '.$timeoutSegundos.' s');
        }

        $rc = null;

        if (preg_match('~'.self::MARCADOR.'(\d+)\s*$~', $saida, $m)) {
            $rc = (int) $m[1];
            $saida = (string) preg_replace('~'.self::MARCADOR.'\d+\s*$~', '', $saida);
        }

        return new ResultadoComando($rc, $saida, $erro);
    }

    public function fechar(): void
    {
        $this->ssh->disconnect();
    }
}
