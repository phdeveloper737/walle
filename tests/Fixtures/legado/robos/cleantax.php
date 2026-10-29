<?php

// Captura sanitizada de suporte.painel_cleantax_worker em 2026-10-21.
return [
    'cliente_sucesso' => [
        'fk_c_cliente' => 9001, 'empresa' => 'MERCADO FICTÍCIO LTDA', 'cnpj' => '00.000.000/0001-00',
        'servidor' => '10.8.0.252', 'porta_http' => '80', 'banco_dados' => 'db_ficticio_ok',
        'loja' => '1', 'auditar_nfe' => 'S',
    ],
    'resposta_sucesso' => '<br /><b>FINALIZADO<b><br />',
    'linha_sucesso' => [
        'fk_c_cliente' => 9001, 'empresa' => 'MERCADO FICTÍCIO LTDA', 'cnpj' => '00.000.000/0001-00',
        'servidor' => '10.8.0.252', 'porta_http' => '80', 'banco_dados' => 'db_ficticio_ok',
        'tempo_execucao_segundos' => '@DURACAO_VARIAVEL@', 'http_code' => 200, 'status_execucao' => 'success',
        'curl_errno' => 0, 'mensagem' => 'FINALIZADO encontrado',
        'url' => 'http://10.8.0.252:80/sistema/app/auditoria_produto/View/cleantaxbot.php?loja=1&dbcliente=db_ficticio_ok&auditar_nfe=S&clean_contingencia=&cod_fiscal=&alimentar_banco_300=&qtd_isentos=&tipo_loja=&cnpj_excecaodelete=&emissao_cupons=',
        'response_preview' => '<br /><b>FINALIZADO<b><br />', 'data_inicio' => '2026-10-27 10:00:00',
        'data_fim' => '2026-10-27 10:00:00', 'data' => '2026-10-27 10:00:00',
    ],
    'cliente_erro' => [
        'fk_c_cliente' => 9002, 'empresa' => 'LOJA FICTÍCIA LTDA', 'cnpj' => '11.111.111/0001-11',
        'servidor' => '10.8.0.253', 'porta_http' => '80', 'banco_dados' => 'db_ficticio_erro',
        'loja' => '1', 'auditar_nfe' => 'S',
    ],
    'linha_erro' => [
        'fk_c_cliente' => 9002, 'empresa' => 'LOJA FICTÍCIA LTDA', 'cnpj' => '11.111.111/0001-11',
        'servidor' => '10.8.0.253', 'porta_http' => '80', 'banco_dados' => 'db_ficticio_erro',
        'tempo_execucao_segundos' => '@DURACAO_VARIAVEL@', 'http_code' => 0, 'status_execucao' => 'exception',
        'curl_errno' => 28, 'mensagem' => 'CURL ERROR 28: cURL error 28: Connection timed out',
        'url' => 'http://10.8.0.253:80/sistema/app/auditoria_produto/View/cleantaxbot.php?loja=1&dbcliente=db_ficticio_erro&auditar_nfe=S&clean_contingencia=&cod_fiscal=&alimentar_banco_300=&qtd_isentos=&tipo_loja=&cnpj_excecaodelete=&emissao_cupons=',
        'response_preview' => '', 'data_inicio' => '2026-10-27 10:00:00', 'data_fim' => '2026-10-27 10:00:00',
        'data' => '2026-10-27 10:00:00',
    ],
];
