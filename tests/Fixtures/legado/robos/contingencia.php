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
    'erro_conexao' => [
        // Origem 220: set.history.cont.php:365 (!connect()), 566 (DELETE history_contingencia) e 568-570 (REPLACE painel zerado).
        'painel_220' => [
            'total_cliente' => 0, 'total_bot' => 0, 'total_sactus' => 0, 'total_versatus' => 0,
        ],
        // Desvio APROVADO (SPEC.md 5.2, PLAN.md task 3.1, relatorio-tecnico.md): no walle a falha NAO zera nem apaga;
        // preserva a última métrica e o histórico válidos e registra a falha em contingencias_coletas.
        'painel_previo' => [
            'total_cliente' => 3, 'total_bot' => 3, 'total_sactus' => 2, 'total_versatus' => 1, 'data' => '2026-10-27 09:00:00',
        ],
        'historico_previo' => [
            'serie' => '2', 'numero' => '501', 'chave' => '52261000000000000190650010000005019000000001',
            'data_fiscal_xml' => '2026-10-19 09:00:00', 'motivo' => 'Rejeição fictícia para teste de paridade', 'software' => 'SACTUS',
        ],
    ],
];
