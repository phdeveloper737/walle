<?php

/*
 * Configurações próprias do walle (robôs, limites, regras do negócio).
 * Os robôs que agem fora do walle ficam DESLIGADOS por padrão: liga um por vez, na virada.
 */
return [

    // Admins: ids do a_user (Help Desk) que podem fazer ações que mexem fora do walle e ver segredos.
    // Mesmo sendo admin, a ação pede a senha do Help Desk de novo (vale por alguns minutos).
    'admins' => array_values(array_filter(array_map('intval', explode(',', (string) env('WALLE_ADMINS', ''))))),
    'confirmar_senha_minutos' => 15,

    // Hosts do Help Desk de produção: toda conexão com um deles só faz leitura (App\Support\HelpDesk\TravaHelpDeskProducao).
    'help_desk' => [
        'hosts_producao' => array_values(array_filter(array_map('trim', explode(',', (string) (env('WALLE_HELP_HOSTS_PRODUCAO') ?: '192.168.10.254'))))),
    ],

    // Robô das autorizadas: consulta na Sefaz as contingências e marca as que já foram autorizadas
    // (substitui o tools/services/autorizadas.php do sistema antigo)
    'autorizadas' => [
        'ativo' => (bool) env('WALLE_AUTORIZADAS_ATIVO', false),
        // Chaves consultadas por ciclo
        'limite_ciclo' => 250,
        // Pausa entre uma chave e outra (segundos)
        'pausa_entre_chaves' => 1,
        // Uma chave não autorizada só é consultada de novo depois de tantas horas
        'intervalo_horas' => 3,
        // Chaves com referência mais antiga que isso são apagadas
        'dias_retencao' => 73,
        // Grava os resultados a cada N chaves consultadas
        'gravar_a_cada' => 50,
        // Semeadura a partir do history_contingencia: linhas por leitura e leituras por ciclo
        'semear_por_leitura' => 2000,
        'semear_leituras_por_ciclo' => 20,
        // Se a Sefaz acusar consumo indevido (656), o robô fica parado por tantos minutos
        'pausa_consumo_indevido_minutos' => 60,
    ],

    // Robô do Sactus: entra por SSH nos servidores dos clientes, coleta o /etc/sactus.cfg, aplica a ACL,
    // atualiza o ERP e aplica os pedidos de edição de flag (substitui o tools/services/sactus.php).
    // Desligado, nada no walle encosta nos servidores dos clientes (nem as ações manuais do painel).
    'sactus' => [
        'ativo' => (bool) env('WALLE_SACTUS_ATIVO', false),
        // Um ciclo por hora (o antigo dormia 3600 s entre ciclos); um ciclo não passa disso
        'limite_ciclo_minutos' => 55,
        // Clientes que o antigo deixa de fora
        'clientes_ignorados' => [18, 1470, 1718, 1896, 1898, 1908, 1943],
        'cfg_caminho' => '/etc/sactus.cfg',
        // true = só o conjunto de credenciais do tipo do servidor; false = tenta o outro se falhar
        'alvo_estrito' => false,
        'ssh' => [
            'conexao_segundos' => 10,
            'comando_segundos' => 30,
        ],
        'prechecagem' => [
            'paralelo' => 50,
            'tcp_segundos' => 4,
        ],
        // service_acl = 'S' no Help: tira todo o acesso do usuário a estes arquivos (setfacl u:<usuário>:---)
        'acl' => [
            'ativo' => true,
            'regras' => [
                '/var/sistema/config.ini' => 'suporte',
                '/etc/sactus.cfg' => 'suporte',
            ],
            'comando_segundos' => 30,
            'instalar_segundos' => 900,
        ],
        // atualizar_erp = 'S' no Help: rsync de /var/sistema a partir do 220 em todo ciclo, como no antigo
        'erp' => [
            'ativo' => true,
            'senha' => env('SACTUS_ERP_SENHA'),
            'origem' => env('SACTUS_ERP_ORIGEM', 'root@192.168.10.220:/var/sistema/*'),
            'porta_origem' => (int) env('SACTUS_ERP_PORTA', 22),
            'destino' => '/var/sistema/',
            'depois' => [
                'chown -R apache.apache /var/sistema',
                'chmod -R 777 /var/sistema',
            ],
            'comando_segundos' => 1800,
            'saida_max' => 4000,
            // ip_interno_servidor nessa faixa: o ERP só é atualizado pela VPN
            'vpn_prefixo' => '10.8.0.',
        ],
        // Pedidos de edição de flag do sactus.cfg (o painel registra, o robô aplica)
        'cfg_edicoes' => [
            'max_por_ciclo' => 20,
            'trava_minutos' => 30,
            'max_tentativas' => 3,
            'backup_dir' => '/home',
            'max_bytes' => 1048576,
            'tmp' => '/tmp/sactus_cfg_edit',
            'pedaco' => 24576,
            'comando_segundos' => 60,
        ],
        // Ações manuais por cliente (pedidas por um admin no painel)
        'acoes' => [
            'YUM_UPDATE' => ['comando' => 'yum -y update', 'segundos' => 1800],
            'INSTALAR_ACL' => ['comando' => 'yum install -y acl', 'segundos' => 900],
        ],
        // A coleta conta como desatualizada depois disso
        'obsoleto_minutos' => 60,
    ],

];
