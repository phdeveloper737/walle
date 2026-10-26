<?php

namespace App\Support\HelpDesk;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Confere de verdade o que o usuário da conexão "help" pode fazer no Help Desk (SHOW GRANTS FOR CURRENT_USER).
 *
 * Só contam como leitura USAGE, SELECT, SHOW VIEW e SHOW DATABASES; qualquer outro privilégio (inclusive
 * WITH GRANT OPTION, PROXY, papéis e formatos que não reconhece) é escrita possível. A linha bruta nunca
 * sai daqui: no MySQL 5.x ela traz "IDENTIFIED BY PASSWORD '*hash'". O resultado fica no cache por
 * walle.help_desk.permissoes_cache_minutos; erro não vai para o cache.
 */
class PermissoesHelpDesk
{
    private const LEITURAS = ['USAGE', 'SELECT', 'SHOW VIEW', 'SHOW DATABASES'];

    private const DESCONHECIDO = 'DESCONHECIDO';

    private const PAPEL = 'PAPEL';

    private const GRANT_OPTION = 'GRANT OPTION';

    public function __construct(private readonly string $conexao = 'help') {}

    public function verificar(): ResultadoPermissoes
    {
        $chave = 'help:permissoes:'.$this->conexao;
        $guardado = Cache::get($chave);

        if (is_array($guardado)) {
            return ResultadoPermissoes::fromArray($guardado);
        }

        try {
            $linhas = array_map(
                fn (object $linha): string => (string) (array_values((array) $linha)[0] ?? ''),
                DB::connection($this->conexao)->select('SHOW GRANTS FOR CURRENT_USER'),
            );
        } catch (Throwable $e) {
            // Sem a mensagem da exceção: a do Laravel traz host e SQL
            Log::warning('Não foi possível conferir as permissões do Help Desk.', ['conexao' => $this->conexao, 'erro' => $e::class]);

            return ResultadoPermissoes::falha(
                'Não foi possível consultar as permissões do Help Desk. Confira se ele está no ar e tente de novo.',
            );
        }

        $resultado = $this->interpretar($linhas);

        Cache::put($chave, $resultado->toArray(), now()->addMinutes((int) config('walle.help_desk.permissoes_cache_minutos', 10)));

        return $resultado;
    }

    /** @param  list<string>  $linhas  linhas do SHOW GRANTS */
    public function interpretar(array $linhas): ResultadoPermissoes
    {
        $escrita = $linhas === [] ? [self::DESCONHECIDO] : [];

        foreach ($linhas as $linha) {
            foreach ($this->privilegiosDaLinha($linha) as $privilegio) {
                if (! in_array($privilegio, self::LEITURAS, true)) {
                    $escrita[] = $privilegio;
                }
            }
        }

        $escrita = array_values(array_unique($escrita));

        return new ResultadoPermissoes($escrita === [], $escrita);
    }

    /**
     * Os nomes dos privilégios concedidos por uma linha (sem colunas, objeto, usuário nem senha).
     *
     * @return list<string>
     */
    private function privilegiosDaLinha(string $linha): array
    {
        $linha = trim($linha);

        // Revogação parcial (MySQL 8) só tira privilégio: ignorá-la nunca faz parecer só leitura
        if (preg_match('/^REVOKE\s/i', $linha)) {
            return [];
        }

        if (! preg_match('/^GRANT\s+/i', $linha, $inicio)) {
            return [self::DESCONHECIDO];
        }

        $resto = substr($linha, strlen($inicio[0]));
        $mascarado = $this->mascarar($resto);

        if ($mascarado === null) {
            return [self::DESCONHECIDO];
        }

        if (! preg_match('/\sON\s/i', $mascarado, $on, PREG_OFFSET_CAPTURE)) {
            // Sem "ON": concessão de papel (GRANT `papel`@`%` TO ...), que traz privilégios que daqui não se vê
            return [preg_match('/\sTO\s/i', $mascarado) ? self::PAPEL : self::DESCONHECIDO];
        }

        $privilegios = [];
        foreach (explode(',', substr($mascarado, 0, $on[0][1])) as $item) {
            // "#" é o que estava entre parênteses (colunas); "%" é texto entre aspas fora deles
            $privilegios[] = preg_match('/^\s*([A-Za-z_]+(?:\s+[A-Za-z_]+)*)\s*#*\s*$/', $item, $nome)
                ? strtoupper((string) preg_replace('/\s+/', ' ', $nome[1]))
                : self::DESCONHECIDO;
        }

        if (preg_match('/\bWITH\s+GRANT\s+OPTION\b/i', substr($mascarado, $on[0][1]))) {
            $privilegios[] = self::GRANT_OPTION;
        }

        return $privilegios;
    }

    /**
     * Troca o que está entre parênteses por "#" e o que está entre aspas (fora de parênteses) por "%",
     * mantendo as posições. Null se uma aspa ou um parêntese ficou aberto.
     */
    private function mascarar(string $texto): ?string
    {
        $mascarado = '';
        $profundidade = 0;
        $aspa = null;
        $tamanho = strlen($texto);

        for ($i = 0; $i < $tamanho; $i++) {
            $caractere = $texto[$i];

            if ($aspa !== null) {
                if ($caractere === '\\' && $aspa !== '`' && $i + 1 < $tamanho) {
                    $mascarado .= str_repeat($profundidade > 0 ? '#' : '%', 2);
                    $i++;

                    continue;
                }

                if ($caractere === $aspa) {
                    $aspa = null;
                }

                $mascarado .= $profundidade > 0 ? '#' : '%';

                continue;
            }

            if ($caractere === "'" || $caractere === '"' || $caractere === '`') {
                $aspa = $caractere;
                $mascarado .= $profundidade > 0 ? '#' : '%';

                continue;
            }

            if ($caractere === '(') {
                $profundidade++;
            }

            $mascarado .= $profundidade > 0 ? '#' : $caractere;

            if ($caractere === ')') {
                $profundidade--;

                if ($profundidade < 0) {
                    return null;
                }

                $mascarado[strlen($mascarado) - 1] = '#';
            }
        }

        return $aspa === null && $profundidade === 0 ? $mascarado : null;
    }
}
