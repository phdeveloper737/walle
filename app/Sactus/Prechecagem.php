<?php

namespace App\Sactus;

/**
 * Antes do SSH, confere em paralelo quais host:porta respondem, para não gastar 10 s de handshake
 * em servidor fora do ar.
 *
 * O antigo fazia ping e, se falhasse, testava a porta. Aqui vai direto na porta TCP: é ela que o SSH
 * usa, e ping de dentro do contêiner exige privilégio extra (firewall de cliente costuma bloquear ICMP).
 */
class Prechecagem
{
    /**
     * @param  array<string, array{host: string, porta: int}>  $pares  'host|porta' => alvo
     * @return array<string, bool> 'host|porta' => respondeu
     */
    public function responde(array $pares): array
    {
        $resultado = [];
        $timeout = (float) config('walle.sactus.prechecagem.tcp_segundos', 4);

        foreach (array_chunk($pares, max(1, (int) config('walle.sactus.prechecagem.paralelo', 50)), true) as $lote) {
            $resultado += $this->lote($lote, $timeout);
        }

        return $resultado;
    }

    /**
     * Abre todas as conexões do lote sem esperar (ASYNC) e vê quais completam dentro do prazo.
     *
     * @param  array<string, array{host: string, porta: int}>  $lote
     * @return array<string, bool>
     */
    private function lote(array $lote, float $timeout): array
    {
        $resultado = array_fill_keys(array_keys($lote), false);
        $abertos = [];

        foreach ($lote as $chave => $par) {
            $socket = @stream_socket_client(
                "tcp://{$par['host']}:{$par['porta']}",
                $codigo,
                $mensagem,
                $timeout,
                STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
            );

            if ($socket !== false) {
                stream_set_blocking($socket, false);
                $abertos[$chave] = $socket;
            }
        }

        $limite = microtime(true) + $timeout;

        while ($abertos !== [] && ($resta = $limite - microtime(true)) > 0) {
            $escrita = array_values($abertos);
            $leitura = $excecao = null;

            if (@stream_select($leitura, $escrita, $excecao, (int) $resta, (int) (fmod($resta, 1) * 1_000_000)) === false) {
                break;
            }

            foreach ($abertos as $chave => $socket) {
                if (! in_array($socket, $escrita, true)) {
                    continue;
                }

                // Gravável = a tentativa terminou; só conectou de fato se tiver o endereço do outro lado
                $resultado[$chave] = @stream_socket_get_name($socket, true) !== false;
                fclose($socket);
                unset($abertos[$chave]);
            }
        }

        foreach ($abertos as $socket) {
            fclose($socket);
        }

        return $resultado;
    }
}
