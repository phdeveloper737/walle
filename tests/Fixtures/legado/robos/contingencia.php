<?php

// Captura sanitizada de suporte.painel_contingency_crawler e history_contingencia em 2026-10-21.
return [
    'entrada_sactus' => [
        'cabecalho' => [
            'store' => '1', 'pdv' => '2', 'cupom' => '501', 'fiscal_date' => '2026-10-19 09:00:00',
            'cod_motivo' => '382', 'motivo' => 'Rejeição fictícia para teste de paridade',
        ],
        'chave' => '52261000000000000190650010000005019000000001',
    ],
    'painel' => [
        'total_cliente' => 1, 'total_bot' => 1,
        'total_sactus' => 1, 'total_versatus' => 0, 'data' => '2026-10-27 10:00:00',
    ],
    'historico' => [[
        'serie' => '2', 'numero' => '501',
        'chave' => '52261000000000000190650010000005019000000001', 'data_fiscal_xml' => '2026-10-19 09:00:00',
        'motivo' => 'Rejeição fictícia para teste de paridade', 'software' => 'SACTUS',
        'data_consulta_sefaz_bot' => null,
    ]],
];
