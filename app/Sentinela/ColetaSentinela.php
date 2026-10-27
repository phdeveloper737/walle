<?php

namespace App\Sentinela;

/**
 * Valor lido de um cliente pelo Bot Sentinela: as colunas de painel_sentinela já normalizadas
 * como o gravarsentinela() do 220 (sentinela.php:324-422): textos aparados e cortados no tamanho da
 * coluna, 'X' quando Sactus/MD5 vêm vazios, 'S'/'N' nas demais flags.
 *
 * Diferenças: nenhuma na escrita. Quirk preservado: no sucesso o 220 passa cpu_uso nulo por trim()
 * e grava 0, não NULL (sentinela.php:365, 392); só a linha de falha fica com cpu_uso NULL.
 */
final readonly class ColetaSentinela
{
    public function __construct(
        public string $status = 'N',
        public int $httpCode = 0,
        public string $erro = '',
        public string $host = '',
        public string $url = '',
        public string $hostname = '',
        public string $os = '',
        public string $kernel = '',
        public string $uptime = '',
        public string $php = '',
        public ?float $cpuUso = null,
        public int $cpuNucleos = 0,
        public float $load1 = 0.0,
        public float $load5 = 0.0,
        public float $load15 = 0.0,
        public int $memTotalMb = 0,
        public int $memUsadoMb = 0,
        public float $memPct = 0.0,
        public float $swapPct = 0.0,
        public string $discoMount = '',
        public float $discoTotalGb = 0.0,
        public float $discoUsadoGb = 0.0,
        public int $discoPct = 0,
        public string $sactusRodando = 'X',
        public string $sactusMtime = '',
        public string $sactusMd5Ok = 'X',
        public string $sactusVersao = '',
        public string $vpnAtivo = 'N',
        public string $vpnTun = '',
        public int $vpnMtu = 0,
        public string $vpnInconsistente = 'N',
        public int $procTotal = 0,
        public ?string $json = null,
        public int $duracaoMs = 0,
    ) {}

    /** Cliente sem resposta: status N, só erro, host, URL, código HTTP e duração (sentinela.php:536, 634). */
    public static function semResposta(string $erro, string $host = '', string $url = '', int $httpCode = 0, int $duracaoMs = 0): self
    {
        return new self(
            status: 'N',
            httpCode: $httpCode,
            erro: self::texto($erro, 500),
            host: self::texto($host, 191),
            url: self::texto($url, 500),
            duracaoMs: $duracaoMs,
        );
    }

    /**
     * Extrai os campos do JSON do sentinela.php do cliente (extrairCampos, sentinela.php:427-489).
     *
     * @param  array<string, mixed>  $d  JSON decodificado
     */
    public static function doJson(array $d, string $host, string $url, int $httpCode, string $corpo, int $duracaoMs): self
    {
        $sis = self::bloco($d, 'sistema');
        $cpu = self::bloco($d, 'cpu');
        $mem = self::bloco($d, 'memoria');
        $swap = self::bloco($d, 'swap');
        $disco = self::bloco($d, 'discoPrincipal');
        $proc = self::bloco($d, 'processos');
        $vpn = self::bloco($d, 'vpn');
        $bin = self::bloco($d, 'sactusBin');
        $sac = self::bloco($d, 'sactus');

        $load = isset($cpu['load']) && is_array($cpu['load']) ? array_values($cpu['load']) : [0, 0, 0];
        $temSac = ! empty($sac['ok']);
        $rodando = ! $temSac ? 'X' : (! empty($sac['rodando']) ? 'S' : 'N');
        $corpo = trim($corpo);

        return new self(
            status: 'S',
            httpCode: $httpCode,
            erro: '',
            host: self::texto($host, 191),
            url: self::texto($url, 500),
            hostname: self::texto($sis['hostname'] ?? '', 191),
            os: self::texto($sis['os'] ?? '', 191),
            kernel: self::texto($sis['kernel'] ?? '', 191),
            uptime: self::texto($sis['uptime'] ?? '', 60),
            php: self::texto($sis['php'] ?? '', 40),
            cpuUso: self::numero($cpu['uso'] ?? 0),
            cpuNucleos: (int) self::numero($cpu['nucleos'] ?? 0),
            load1: self::numero($load[0] ?? 0),
            load5: self::numero($load[1] ?? 0),
            load15: self::numero($load[2] ?? 0),
            memTotalMb: (int) self::numero($mem['total'] ?? 0),
            memUsadoMb: (int) self::numero($mem['realUsado'] ?? 0),
            memPct: self::numero($mem['pct'] ?? 0),
            swapPct: self::numero($swap['pct'] ?? 0),
            discoMount: self::texto($disco['mount'] ?? '', 120),
            discoTotalGb: self::numero($disco['totalGb'] ?? 0),
            discoUsadoGb: self::numero($disco['usadoGb'] ?? 0),
            discoPct: (int) self::numero($disco['pct'] ?? 0),
            sactusRodando: $rodando,
            sactusMtime: $temSac ? self::texto($sac['mtime'] ?? '', 40) : '',
            sactusMd5Ok: ! empty($bin['bateu']) ? 'S' : 'N',
            sactusVersao: self::texto($bin['versao'] ?? '', 60),
            vpnAtivo: ! empty($vpn['ativo']) ? 'S' : 'N',
            vpnTun: self::texto($vpn['tun'] ?? '', 20),
            vpnMtu: (int) self::numero($vpn['mtu'] ?? 0),
            vpnInconsistente: ! empty($vpn['inconsistente']) ? 'S' : 'N',
            procTotal: (int) self::numero($proc['total'] ?? 0),
            json: $corpo !== '' ? $corpo : null,
            duracaoMs: $duracaoMs,
        );
    }

    /**
     * Linha de painel_sentinela (sem fk_c_cliente e data).
     *
     * @return array<string, mixed>
     */
    public function colunas(): array
    {
        return [
            'status' => $this->status, 'http_code' => $this->httpCode, 'erro' => $this->erro,
            'host' => $this->host, 'url' => $this->url, 'hostname' => $this->hostname, 'os' => $this->os,
            'kernel' => $this->kernel, 'uptime' => $this->uptime, 'php' => $this->php,
            'cpu_uso' => $this->cpuUso, 'cpu_nucleos' => $this->cpuNucleos,
            'load1' => $this->load1, 'load5' => $this->load5, 'load15' => $this->load15,
            'mem_total_mb' => $this->memTotalMb, 'mem_usado_mb' => $this->memUsadoMb,
            'mem_pct' => $this->memPct, 'swap_pct' => $this->swapPct,
            'disco_mount' => $this->discoMount, 'disco_total_gb' => $this->discoTotalGb,
            'disco_usado_gb' => $this->discoUsadoGb, 'disco_pct' => $this->discoPct,
            'sactus_rodando' => $this->sactusRodando, 'sactus_mtime' => $this->sactusMtime,
            'sactus_md5_ok' => $this->sactusMd5Ok, 'sactus_versao' => $this->sactusVersao,
            'vpn_ativo' => $this->vpnAtivo, 'vpn_tun' => $this->vpnTun, 'vpn_mtu' => $this->vpnMtu,
            'vpn_inconsistente' => $this->vpnInconsistente, 'proc_total' => $this->procTotal,
            'json' => $this->json, 'duracao_ms' => $this->duracaoMs,
        ];
    }

    /**
     * @param  array<string, mixed>  $d
     * @return array<string, mixed>
     */
    private static function bloco(array $d, string $chave): array
    {
        return isset($d[$chave]) && is_array($d[$chave]) ? $d[$chave] : [];
    }

    private static function texto(mixed $valor, int $max): string
    {
        return mb_substr(trim(is_scalar($valor) ? (string) $valor : ''), 0, $max);
    }

    private static function numero(mixed $valor): float
    {
        return is_scalar($valor) ? (float) trim((string) $valor) : 0.0;
    }
}
