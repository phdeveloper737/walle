<?php

// Captura sanitizada de suporte.painel_sentinela e sentinela.php em 2026-10-21.
return [
    'host_sucesso' => '10.8.0.250',
    'host_erro' => '10.8.0.251',
    'json' => json_encode([
        'ok' => true,
        'sistema' => ['hostname' => 'servidor-ficticio', 'os' => 'Linux Fictício', 'kernel' => 'kernel-ficticio', 'uptime' => '2d 3h', 'php' => '8.0.0'],
        'cpu' => ['uso' => 1.6, 'nucleos' => 4, 'load' => [0.03, 0.11, 0.17]],
        'memoria' => ['total' => 4096, 'realUsado' => 1024, 'pct' => 25.0],
        'swap' => ['pct' => 0.2],
        'discoPrincipal' => ['mount' => '/', 'totalGb' => 100.0, 'usadoGb' => 30.0, 'pct' => 30],
        'processos' => ['total' => 100],
        'vpn' => ['ativo' => true, 'tun' => 'tun0', 'mtu' => 1279, 'inconsistente' => false],
        'sactusBin' => ['bateu' => false, 'versao' => '0.0.0-ficticia'],
        'sactus' => ['ok' => true, 'rodando' => true, 'mtime' => '27/10/2026 09:59:00'],
    ], JSON_THROW_ON_ERROR),
    'linha_sucesso' => [
        'status' => 'S', 'http_code' => 200, 'erro' => '', 'host' => '10.8.0.250',
        'url' => 'http://10.8.0.250/sentinela.php?json=S', 'hostname' => 'servidor-ficticio',
        'os' => 'Linux Fictício', 'kernel' => 'kernel-ficticio', 'uptime' => '2d 3h', 'php' => '8.0.0',
        'cpu_uso' => '1.6', 'cpu_nucleos' => 4, 'load1' => '0.03', 'load5' => '0.11', 'load15' => '0.17',
        'mem_total_mb' => 4096, 'mem_usado_mb' => 1024, 'mem_pct' => '25.0', 'swap_pct' => '0.2',
        'disco_mount' => '/', 'disco_total_gb' => '100.0', 'disco_usado_gb' => '30.0', 'disco_pct' => 30,
        'sactus_rodando' => 'S', 'sactus_mtime' => '27/10/2026 09:59:00', 'sactus_md5_ok' => 'N',
        'sactus_versao' => '0.0.0-ficticia', 'vpn_ativo' => 'S', 'vpn_tun' => 'tun0', 'vpn_mtu' => 1279,
        'vpn_inconsistente' => 'N', 'proc_total' => 100, 'json' => '@JSON_CAPTURADO@',
        'duracao_ms' => '@DURACAO_VARIAVEL@', 'data' => '2026-10-27 10:00:00',
    ],
    'linha_erro' => [
        'status' => 'N', 'http_code' => 0,
        'erro' => '10.8.0.251 => cURL error 28: Connection timed out after 8001 milliseconds',
        'host' => '10.8.0.251', 'url' => 'http://10.8.0.251/sentinela.php?json=S',
        'hostname' => '', 'os' => '', 'kernel' => '', 'uptime' => '', 'php' => '', 'cpu_uso' => null,
        'cpu_nucleos' => 0, 'load1' => '0.00', 'load5' => '0.00', 'load15' => '0.00',
        'mem_total_mb' => 0, 'mem_usado_mb' => 0, 'mem_pct' => '0.0', 'swap_pct' => '0.0',
        'disco_mount' => '', 'disco_total_gb' => '0.0', 'disco_usado_gb' => '0.0', 'disco_pct' => 0,
        'sactus_rodando' => 'X', 'sactus_mtime' => '', 'sactus_md5_ok' => 'X', 'sactus_versao' => '',
        'vpn_ativo' => 'N', 'vpn_tun' => '', 'vpn_mtu' => 0, 'vpn_inconsistente' => 'N', 'proc_total' => 0,
        'json' => null, 'duracao_ms' => '@DURACAO_VARIAVEL@', 'data' => '2026-10-27 10:00:00',
    ],
];
