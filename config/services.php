<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Servidores MySQL dos clientes (contingências do Sactus/PDV Linux), SOMENTE LEITURA
    'clientes' => [
        // Docker da JM: o primeiro é o padrão quando o cadastro não diz o servidor
        'docker_hosts' => ['192.168.10.244', '192.168.10.235'],
        'docker_senha_root' => env('CLIENTES_DOCKER_SENHA_ROOT'),
        // Segundos para conectar (o sistema antigo esperava até 20)
        'timeout' => 10,
    ],

    // API do Help Desk (192.168.10.251) que entrega a senha do banco do Help
    'help' => [
        'api_url' => env('HELP_API_URL', 'http://192.168.10.251/api'),
        'api_token' => env('HELP_API_TOKEN'),
        // Por quanto tempo a senha fica guardada no cache antes de perguntar de novo à API
        'senha_cache_minutos' => 10,
    ],

];
