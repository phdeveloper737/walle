<?php

// Captura sanitizada de suporte.task_scheduler_querys e task_scheduler_querys_log em 2026-06-24.
return [
    'tarefa' => [
        'descricao' => 'Tarefa fictícia de paridade',
        'query_execute' => 'U0VMRUNUIDEgQVMgbG9nX3Rhc2s=',
        'ativo' => 'S',
        'deletado' => 'N',
    ],
    'log_ok' => 'T0s=',
    'log_erro_conexao' => 'FALHA AO CONECTAR NO CLIENTE: falha fictícia ao conectar',

    // Origem 220: task.scheduler.bot.php:164/168/183/202/232/241. O 220 grava
    // "FALHA AO CONECTAR NO CLIENTE: host | user | db | pass | porta" (senha do cliente em claro).
    // Desvio por segurança (RoboTarefas.php, docblock "Diferenças"; RoboTarefasTest "sem senha"):
    // o walle mantém o prefixo (o retry tipo_bot=2 do 220 filtra SUBSTRING(log_query,1,5)='FALHA', linha 22)
    // e NÃO grava usuário, banco nem senha. Ficticios abaixo só provam que não vazam.
    'prefixo_falha_conexao' => 'FALHA AO CONECTAR NO CLIENTE: ',
    'cadastro_servidor' => [
        'servidor_mysql' => '10.99.99.9', 'porta_mysql' => '3399', 'usuario_mysql' => 'usuario_ficticio',
        'banco_mysql' => 'banco_ficticio', 'senha_mysql' => 'SenhaFicticia-123',
    ],

    // Origem 220: task.scheduler.bot.php:213-227 (query sem crase, aspas e ';'; log = base64("Erro: " . $mysqli->error)).
    // O texto do MySQL e o nome do banco dependem do ambiente: compara-se o formato, não o texto literal.
    'query_com_erro_sql' => 'SELECT * FROM tabela_inexistente_paridade;',
    'log_erro_query_prefixo' => 'Erro: ',
    'log_erro_query_padrao' => "/^Erro: Table '[^']+\\.tabela_inexistente_paridade' doesn't exist$/",
];
