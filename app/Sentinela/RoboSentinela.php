<?php

namespace App\Sentinela;

use App\Models\Auditoria;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Substitui sentinela.php (consultorias/api/tools/services do 220): para cada cliente do Help Desk
 * faz GET http://<host>/sentinela.php?json=S e grava a linha única do cliente em painel_sentinela.
 *
 * Valores do 220 (sentinela.php): timeout total de 35 segundos (HTTP_TIMEOUT, linha 41), conexão de
 * 8 segundos (HTTP_CONNECT_TIMEOUT, linha 40), até 3 redirecionamentos (199), intervalo entre ciclos
 * de 600 s (26, fica para a agenda da task 5.1) e watchdog de 3600 s por ciclo (27, 557). Clientes:
 * c_cliente.deletado = 'N' e ip_externo_servidor começando por 10.8.0. (268-273); hosts candidatos
 * ip_externo_servidor e servidor_docker, só os 10.8.0.* são consultados (47, 571).
 *
 * Diferenças: sem PID/sleep/health (o Job e a agenda cuidam disso); cada cliente tem Cache::lock;
 * a falha inesperada de um cliente é reportada e gravada como "sem resposta"; cada coleta passa por
 * Auditoria::registrar() (sem o JSON). Não escreve no Help Desk (só lê clientes). TLS sem verificação
 * e User-Agent iguais ao 220.
 */
class RoboSentinela
{
    /** Timeout total da requisição, em segundos (sentinela.php:41). */
    public const TIMEOUT_SEGUNDOS = 35;

    /** Timeout de conexão TCP, em segundos (sentinela.php:40). */
    public const TIMEOUT_CONEXAO_SEGUNDOS = 8;

    /** Tempo máximo de um ciclo, em segundos (sentinela.php:27). */
    public const WATCHDOG_SEGUNDOS = 3600;

    public const TRAVA_SEGUNDOS = 3700;

    /** Colunas do Help Desk tentadas como host, em ordem (sentinela.php:47). */
    private const HOST_CAMPOS = ['ip_externo_servidor', 'servidor_docker'];

    private const PREFIXO_VPN = '10.8.0.';

    public function ligado(): bool
    {
        return (bool) config('walle.sentinela.ativo');
    }

    public function rodarCiclo(): void
    {
        if (! $this->ligado()) {
            return;
        }

        $inicio = time();

        foreach ($this->clientes() as $clienteId => $campos) {
            if (time() - $inicio > self::WATCHDOG_SEGUNDOS) {
                break;
            }

            $trava = Cache::lock('sentinela:cliente:'.$clienteId, self::TRAVA_SEGUNDOS);

            if (! $trava->get()) {
                continue;
            }

            try {
                $coleta = $this->coletar($campos);
            } catch (Throwable $e) {
                report($e);
                $coleta = ColetaSentinela::semResposta($e->getMessage());
            }

            try {
                $this->gravar($clienteId, $coleta);
            } catch (Throwable $e) {
                report($e);
            } finally {
                $trava->release();
            }
        }
    }

    /**
     * Um cliente por id, com a primeira linha do cadastro do servidor (o GROUP BY do 220).
     *
     * @return array<int, array<string, string>>
     */
    private function clientes(): array
    {
        $linhas = DB::connection('help')->table('informacao_tecnica_dataserver as i')
            ->join('c_cliente as c', 'c.id', '=', 'i.fk_c_cliente')
            ->where('c.deletado', 'N')
            ->whereRaw("LEFT(TRIM(i.ip_externo_servidor), 7) = '10.8.0.'")
            ->orderBy('i.id')
            ->select('c.id as id_client', 'i.ip_externo_servidor', 'i.servidor_docker')
            ->get();

        $clientes = [];

        foreach ($linhas as $linha) {
            $id = (int) $linha->id_client;

            if ($id > 0 && ! isset($clientes[$id])) {
                $clientes[$id] = [
                    'ip_externo_servidor' => trim((string) $linha->ip_externo_servidor),
                    'servidor_docker' => trim((string) ($linha->servidor_docker ?? '')),
                ];
            }
        }

        return $clientes;
    }

    /** @param  array<string, string>  $campos */
    private function coletar(array $campos): ColetaSentinela
    {
        $hosts = $this->hosts($campos);

        if ($hosts === []) {
            return ColetaSentinela::semResposta('Nenhum host HTTP valido nas colunas: '.implode(', ', self::HOST_CAMPOS));
        }

        $inicio = microtime(true);
        $erros = [];
        $ultimoHost = $hosts[0];
        $ultimaUrl = '';
        $codigo = 0;

        foreach ($hosts as $host) {
            if (! str_starts_with($host, self::PREFIXO_VPN)) {
                continue;
            }

            $ultimoHost = $host;
            $ultimaUrl = 'http://'.$host.'/sentinela.php?json=S';
            [$codigo, $corpo, $erroCurl] = $this->requisitar($ultimaUrl);

            if ($corpo === '') {
                $erros[] = $host.' => '.($erroCurl !== '' ? $erroCurl : 'sem resposta').($codigo !== 0 ? ' (HTTP '.$codigo.')' : '');

                continue;
            }

            if ($codigo !== 200) {
                $erros[] = $host.' => HTTP '.$codigo;

                continue;
            }

            $dados = json_decode($corpo, true);

            if (! is_array($dados)) {
                $erros[] = $host.' => resposta nao e JSON valido';

                continue;
            }

            if (empty($dados['ok'])) {
                $detalhe = $dados['erro'] ?? 'sem detalhe';
                $erros[] = $host.' => sentinela.php ok=false: '.(is_scalar($detalhe) ? $detalhe : 'sem detalhe');

                continue;
            }

            return ColetaSentinela::doJson($dados, $host, $ultimaUrl, $codigo, $corpo, $this->duracaoMs($inicio));
        }

        return ColetaSentinela::semResposta(implode(' | ', $erros), $ultimoHost, $ultimaUrl, $codigo, $this->duracaoMs($inicio));
    }

    /** @return array{int, string, string} código HTTP, corpo e erro de transporte */
    private function requisitar(string $url): array
    {
        try {
            $resposta = Http::connectTimeout(self::TIMEOUT_CONEXAO_SEGUNDOS)
                ->timeout(self::TIMEOUT_SEGUNDOS)
                ->withUserAgent('sactus-sentinela-worker/1.0')
                ->withOptions(['verify' => false, 'allow_redirects' => ['max' => 3]])
                ->acceptJson()
                ->get($url);
        } catch (ConnectionException $e) {
            return [0, '', $e->getMessage()];
        }

        return [$resposta->status(), $resposta->body(), ''];
    }

    /**
     * @param  array<string, string>  $campos
     * @return list<string>
     */
    private function hosts(array $campos): array
    {
        $hosts = [];

        foreach (self::HOST_CAMPOS as $campo) {
            $host = trim((string) preg_replace('~^[a-z][a-z0-9+.-]*://~i', '', trim($campos[$campo] ?? '')));
            $host = trim((string) preg_replace('~[/?#].*$~', '', $host));

            if (preg_match('~^([^:\[\]]+):(\d{1,5})$~', $host, $m)) {
                $host = $m[1];
            }

            $host = trim($host);

            if ($host === '' || ! preg_match('~^[A-Za-z0-9._-]+$~', $host)) {
                continue;
            }

            if (! in_array(strtolower($host), array_map('strtolower', $hosts), true)) {
                $hosts[] = $host;
            }
        }

        return $hosts;
    }

    private function duracaoMs(float $inicio): int
    {
        return (int) round((microtime(true) - $inicio) * 1000);
    }

    private function gravar(int $clienteId, ColetaSentinela $coleta): void
    {
        $colunas = $coleta->colunas();
        $colunas['sactus_rodando'] = $colunas['sactus_rodando'] ?: 'X';
        $colunas['sactus_md5_ok'] = $colunas['sactus_md5_ok'] ?: 'X';

        DB::transaction(function () use ($clienteId, $colunas, $coleta): void {
            DB::table('painel_sentinela')->upsert(
                [['fk_c_cliente' => $clienteId, ...$colunas, 'data' => now()]],
                ['fk_c_cliente'],
                [...array_keys($colunas), 'data'],
            );

            Auditoria::registrar('sentinela.coletar', $clienteId, [
                'origem' => 'automatica', 'robo' => 'sentinela',
                'status' => $coleta->status, 'http_code' => $coleta->httpCode,
            ]);
        });
    }
}
