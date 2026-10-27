<?php

namespace App\CleanTax;

use App\Models\Auditoria;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;
use Throwable;

/**
 * Substitui cleantax.php (consultorias/api/tools/services do 220): busca os clientes na API
 * https://jmsistemas.net/cleantax, chama o cleantaxbot.php de cada um e grava a linha única do cliente
 * em painel_cleantax_worker (INSERT ... ON DUPLICATE KEY UPDATE por fk_c_cliente).
 *
 * Valores do 220 (cleantax.php): API_ENDPOINT (25), API_TIMEOUT 30 s (28), VALIDATE_TIMEOUT 300 s (29),
 * conexão 15 s (CURLOPT_CONNECTTIMEOUT, 414 e 733), MAX_RETRY 3 (32) com espera de 5 s (33) para erro cURL
 * ou HTTP 500/502/503/504 (751-764), SERIAL_HOSTS e intervalo de 3 s entre o fim de uma requisição e o
 * início da próxima no mesmo host serial (41, 45), intervalo entre ciclos de 30 s (30, fica para a agenda
 * da task 5.1), MAX_PARALLEL 3 (24). SSL sem verificação (51).
 *
 * Diferenças: uma requisição por vez (sem curl_multi; o MAX_PARALLEL fica 1) e Cache::lock por host em
 * todos os hosts, não só nos seriais; timeout (cURL 28) nunca tem retry em qualquer host (no 220 só nos
 * seriais); o intervalo de 3 s vale só nos hosts seriais (config walle.cleantax.hosts_seriais); o token
 * vem de config('walle.cleantax.token') e nunca é gravado (o 220 tem um valor padrão no código, não copiado);
 * sem PID/log em arquivo/relatório JSON (o Job e a agenda cuidam disso); cada requisição passa por
 * Auditoria::registrar() sem URL, corpo nem token; falha da API (3 tentativas) encerra o ciclo antes do
 * reset do painel e é registrada em Auditoria 'cleantax.api_falhou'. Host ocupado por outro processo é
 * adiado para o fim da fila (como o 220) e, esgotado o limite de esperas, o cliente fica para o próximo
 * ciclo. Não escreve no Help Desk.
 */
class RoboCleanTax
{
    public const API_ENDPOINT = 'https://jmsistemas.net/cleantax';

    /** Timeout da chamada à API de clientes, em segundos (cleantax.php:28). */
    public const API_TIMEOUT_SEGUNDOS = 30;

    /** Timeout total do cleantaxbot.php, em segundos (cleantax.php:29). */
    public const VALIDATE_TIMEOUT_SEGUNDOS = 300;

    /** Timeout de conexão TCP, em segundos (cleantax.php:414, 733). */
    public const TIMEOUT_CONEXAO_SEGUNDOS = 15;

    /** Retries após a primeira tentativa (cleantax.php:32). */
    public const MAX_RETRY = 3;

    /** Espera antes de cada retry, em segundos (cleantax.php:33). */
    public const ESPERA_RETRY_SEGUNDOS = 5;

    /** Intervalo mínimo após o fim de uma requisição em host serial, em segundos (cleantax.php:45). */
    public const INTERVALO_HOST_SERIAL_SEGUNDOS = 3;

    /** Hosts que não aceitam requisições simultâneas, com intervalo (cleantax.php:41). */
    public const HOSTS_SERIAIS = ['177.136.203.233'];

    /** Validade da trava do host: timeout total mais as tentativas, em segundos. */
    public const TRAVA_SEGUNDOS = 1500;

    /** Quantas vezes se espera 1 s por um host ocupado por outro processo antes de deixar para o próximo ciclo. */
    public const ESPERAS_MAXIMAS_POR_HOST_OCUPADO = 400;

    private const TIMEOUT_CURL = 28;

    private const ERRO_URL = 'Dados invalidos para montar URL. servidor/porta_http ausente.';

    public function ligado(): bool
    {
        return (bool) config('walle.cleantax.ativo');
    }

    public function rodarCiclo(): void
    {
        if (! $this->ligado()) {
            return;
        }

        $clientes = $this->clientesDaApi();

        if ($clientes === null || $clientes === []) {
            return;
        }

        $this->resetarPainel();

        $fila = $this->intercalarPorHost($clientes);
        $esperas = 0;

        while ($fila !== []) {
            $cliente = array_shift($fila);
            $host = $this->host($cliente);
            $trava = Cache::lock('cleantax:host:'.$host, self::TRAVA_SEGUNDOS);

            if (! $trava->get()) {
                $fila[] = $cliente;

                if (++$esperas > self::ESPERAS_MAXIMAS_POR_HOST_OCUPADO) {
                    break;
                }

                Sleep::for(1)->second();

                continue;
            }

            try {
                $this->processar($cliente, $host);
            } catch (Throwable $e) {
                report($e);
            } finally {
                $trava->release();
            }
        }
    }

    /**
     * @return list<array<string, mixed>>|null null quando a API falhou nas tentativas
     */
    private function clientesDaApi(): ?array
    {
        $ultimoErro = 'sem detalhe';

        for ($tentativa = 1; $tentativa <= self::MAX_RETRY; $tentativa++) {
            try {
                $resposta = Http::connectTimeout(self::TIMEOUT_CONEXAO_SEGUNDOS)
                    ->timeout(self::API_TIMEOUT_SEGUNDOS)
                    ->withHeaders($this->cabecalhos())
                    ->withOptions(['verify' => false])
                    ->asJson()
                    ->post(self::API_ENDPOINT, ['type' => 'listClient', 'auditar_nfe' => 'S']);
            } catch (ConnectionException $e) {
                $ultimoErro = 'Erro cURL: '.$e->getMessage();
                Sleep::for(self::ESPERA_RETRY_SEGUNDOS)->seconds();

                continue;
            }

            $json = $resposta->successful() ? json_decode($resposta->body(), true) : null;

            if (is_array($json) && ($json['status'] ?? null) === true) {
                $lista = is_array($json['message'] ?? null) ? $json['message'] : [];

                return array_values(array_filter($lista, is_array(...)));
            }

            $ultimoErro = $resposta->successful() ? 'resposta invalida ou status diferente de true' : 'HTTP '.$resposta->status();
            Sleep::for(self::ESPERA_RETRY_SEGUNDOS)->seconds();
        }

        report(new RuntimeException('CleanTax: API de clientes indisponivel: '.mb_substr($ultimoErro, 0, 300)));
        Auditoria::registrar('cleantax.api_falhou', null, [
            'origem' => 'automatica', 'robo' => 'cleantax', 'tentativas' => self::MAX_RETRY, 'erro' => mb_substr($ultimoErro, 0, 300),
        ]);

        return null;
    }

    /** @return array<string, string> */
    private function cabecalhos(): array
    {
        $token = (string) config('walle.cleantax.token', '');

        return $token === '' ? [] : ['Authorization' => $token, 'X-Authorization' => $token];
    }

    /** Início do ciclo: limpa erros antigos do painel (cleantax.php:937-939). */
    private function resetarPainel(): void
    {
        DB::table('painel_cleantax_worker')->update(['http_code' => 200, 'status_execucao' => 'success']);
    }

    /**
     * Um cliente de cada host por rodada, como o interleaveClientsByHost do 220.
     *
     * @param  list<array<string, mixed>>  $clientes
     * @return list<array<string, mixed>>
     */
    private function intercalarPorHost(array $clientes): array
    {
        $grupos = [];

        foreach ($clientes as $cliente) {
            $grupos[$this->host($cliente)][] = $cliente;
        }

        $ordenados = [];

        do {
            $adicionou = false;

            foreach ($grupos as $host => $fila) {
                if ($fila !== []) {
                    $ordenados[] = array_shift($grupos[$host]);
                    $adicionou = true;
                }
            }
        } while ($adicionou);

        return $ordenados;
    }

    /** @param  array<string, mixed>  $cliente */
    private function host(array $cliente): string
    {
        $h = strtolower(trim($this->valor($cliente, 'servidor')));
        $h = (string) preg_replace('#^[a-z]+://#', '', $h);
        $h = (string) preg_replace('~[/?\#].*$~', '', $h);
        $h = (string) preg_replace('#:\d+$#', '', $h);
        $h = rtrim($h, '.');

        return $h !== '' ? $h : 'UNKNOWN_HOST';
    }

    /** @param  array<string, mixed>  $cliente */
    private function valor(array $cliente, string $chave): string
    {
        return isset($cliente[$chave]) && is_scalar($cliente[$chave]) ? trim((string) $cliente[$chave]) : '';
    }

    /** @param  array<string, mixed>  $cliente */
    private function idDoPainel(array $cliente): int
    {
        foreach (['fk_c_cliente', 'id_cliente', 'id_client', 'id'] as $chave) {
            if ((int) ($cliente[$chave] ?? 0) > 0) {
                return (int) $cliente[$chave];
            }
        }

        return 0;
    }

    /** @param  array<string, mixed>  $cliente */
    private function url(array $cliente): string
    {
        $servidor = $this->valor($cliente, 'servidor');
        $porta = $this->valor($cliente, 'porta_http');

        if ($servidor === '' || $porta === '') {
            return '';
        }

        $query = http_build_query([
            'loja' => $this->valor($cliente, 'loja'),
            'dbcliente' => $this->valor($cliente, 'banco_dados'),
            'auditar_nfe' => $this->valor($cliente, 'auditar_nfe'),
            'clean_contingencia' => $this->valor($cliente, 'clean_contingencia'),
            'cod_fiscal' => $this->valor($cliente, 'cod_fiscal'),
            'alimentar_banco_300' => $this->valor($cliente, 'inserir_300'),
            'qtd_isentos' => $this->valor($cliente, 'qtd_isentos'),
            'tipo_loja' => $this->valor($cliente, 'tipo_loja'),
            'cnpj_excecaodelete' => $this->valor($cliente, 'cnpj_excecaodelete'),
            'emissao_cupons' => $this->valor($cliente, 'emissao_cupons'),
        ], '', '&', PHP_QUERY_RFC3986);

        return 'http://'.$servidor.':'.$porta.'/sistema/app/auditoria_produto/View/cleantaxbot.php?'.$query;
    }

    private function hostSerial(string $host): bool
    {
        /** @var list<string> $seriais */
        $seriais = (array) config('walle.cleantax.hosts_seriais', self::HOSTS_SERIAIS);

        return in_array($host, array_map(strtolower(...), $seriais), true);
    }

    /** @param  array<string, mixed>  $cliente */
    private function processar(array $cliente, string $host): void
    {
        if ($this->idDoPainel($cliente) <= 0) {
            return;
        }

        $url = $this->url($cliente);

        if ($url === '') {
            $agora = now();
            $this->gravar($cliente, [
                'tempo_execucao_segundos' => 0, 'http_code' => 0, 'status_execucao' => 'invalid_url', 'curl_errno' => 0,
                'mensagem' => self::ERRO_URL, 'url' => '', 'response_preview' => '', 'data_inicio' => $agora, 'data_fim' => $agora,
            ]);

            return;
        }

        $serial = $this->hostSerial($host);
        $chaveLivreEm = 'cleantax:host:'.$host.':livre_em';

        for ($retry = 0; ; $retry++) {
            if ($serial) {
                $espera = (float) Cache::get($chaveLivreEm, 0) + self::INTERVALO_HOST_SERIAL_SEGUNDOS - (float) now()->format('U.u');

                if ($espera > 0) {
                    Sleep::for((int) ceil($espera * 1000))->milliseconds();
                }
            }

            $inicio = now();
            [$codigo, $corpo, $errno, $erro] = $this->requisitar($url);
            $fim = now();

            if ($serial) {
                Cache::put($chaveLivreEm, (float) $fim->format('U.u'), self::TRAVA_SEGUNDOS);
            }

            if ($retry < self::MAX_RETRY && $this->deveTentarDeNovo($errno, $codigo)) {
                Sleep::for(self::ESPERA_RETRY_SEGUNDOS)->seconds();

                continue;
            }

            break;
        }

        if ($errno !== 0) {
            $status = 'exception';
            $mensagem = 'CURL ERROR '.$errno.': '.$erro;
        } elseif ($codigo === 200) {
            $achou = str_contains($corpo, 'FINALIZADO');
            $status = $achou ? 'success' : 'warning';
            $mensagem = $achou ? 'FINALIZADO encontrado' : 'HTTP 200 sem FINALIZADO';
        } else {
            $status = 'error';
            $mensagem = 'HTTP '.$codigo;
        }

        $this->gravar($cliente, [
            'tempo_execucao_segundos' => round(abs((float) $fim->format('U.u') - (float) $inicio->format('U.u')), 3), 'http_code' => $codigo,
            'status_execucao' => $status, 'curl_errno' => $errno, 'mensagem' => $mensagem, 'url' => $url,
            'response_preview' => $corpo, 'data_inicio' => $inicio, 'data_fim' => $fim,
        ]);
    }

    /** @return array{int, string, int, string} código HTTP, corpo, errno cURL e mensagem de transporte */
    private function requisitar(string $url): array
    {
        try {
            $resposta = Http::connectTimeout(self::TIMEOUT_CONEXAO_SEGUNDOS)
                ->timeout(self::VALIDATE_TIMEOUT_SEGUNDOS)
                ->withHeaders(['Accept' => 'application/json', 'Content-Type' => 'application/json', ...$this->cabecalhos()])
                ->withOptions(['verify' => false])
                ->get($url);
        } catch (ConnectionException $e) {
            $errno = preg_match('/cURL error (\d+)/', $e->getMessage(), $m) ? (int) $m[1] : 7;

            return [0, '', $errno, $e->getMessage()];
        }

        return $this->desmontar($resposta);
    }

    /** @return array{int, string, int, string} */
    private function desmontar(Response $resposta): array
    {
        return [$resposta->status(), $resposta->body(), 0, ''];
    }

    /** Erro de transporte (menos timeout) ou HTTP 500/502/503/504 (cleantax.php:751-764). */
    private function deveTentarDeNovo(int $errno, int $codigo): bool
    {
        if ($errno === self::TIMEOUT_CURL) {
            return false;
        }

        return $errno !== 0 || in_array($codigo, [500, 502, 503, 504], true);
    }

    /**
     * @param  array<string, mixed>  $cliente
     * @param  array<string, mixed>  $dados
     */
    private function gravar(array $cliente, array $dados): void
    {
        $id = $this->idDoPainel($cliente);
        $inicio = $dados['data_inicio'] instanceof Carbon ? $dados['data_inicio']->format('Y-m-d H:i:s') : null;
        $fim = $dados['data_fim'] instanceof Carbon ? $dados['data_fim']->format('Y-m-d H:i:s') : null;

        $linha = [
            'fk_c_cliente' => $id,
            'empresa' => $this->valor($cliente, 'empresa'),
            'cnpj' => $this->valor($cliente, 'cnpj'),
            'servidor' => $this->valor($cliente, 'servidor'),
            'porta_http' => $this->valor($cliente, 'porta_http'),
            'banco_dados' => $this->valor($cliente, 'banco_dados'),
            'tempo_execucao_segundos' => $dados['tempo_execucao_segundos'],
            'http_code' => (int) $dados['http_code'],
            'status_execucao' => mb_substr((string) $dados['status_execucao'], 0, 30),
            'curl_errno' => (int) $dados['curl_errno'],
            'mensagem' => mb_substr((string) $dados['mensagem'], 0, 500),
            'url' => (string) $dados['url'],
            'response_preview' => mb_substr((string) $dados['response_preview'], 0, 4000),
            'data_inicio' => $inicio,
            'data_fim' => $fim,
        ];

        DB::transaction(function () use ($linha, $id): void {
            DB::table('painel_cleantax_worker')->upsert(
                [[...$linha, 'data' => now()]],
                ['fk_c_cliente'],
                [...array_diff(array_keys($linha), ['fk_c_cliente']), 'data'],
            );

            Auditoria::registrar('cleantax.auditar', $id, [
                'origem' => 'automatica', 'robo' => 'cleantax',
                'status' => $linha['status_execucao'], 'http_code' => $linha['http_code'],
            ]);
        });
    }
}
