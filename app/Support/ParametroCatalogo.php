<?php

namespace App\Support;

/**
 * Catálogo de parâmetros configuráveis de todos os robôs do walle.
 * Define nome e ajuda (texto da tela), defaults, unidades, fronteiras de validação (min, max, tipo, segredo) e se requer decisão do usuário.
 */
class ParametroCatalogo
{
    /**
     * Retorna o catálogo completo de todos os robôs.
     *
     * @return array<string, array<string, array{nome: string, ajuda: string, default: mixed, unidade: string, fronteira: array<string, mixed>, requer_decisao_usuario?: bool}>>
     */
    public static function todos(): array
    {
        return [
            'autorizadas' => [
                'ativo' => [
                    'nome' => 'Robô ligado',
                    'ajuda' => 'Liga ou desliga a execução do robô neste servidor; só roda se o .env também permitir.',
                    'default' => false,
                    'unidade' => 'boolean',
                    'fronteira' => ['tipo' => 'bool'],
                ],
                'limite_ciclo' => [
                    'nome' => 'Limite por ciclo',
                    'ajuda' => 'Máximo de chaves consultadas na Sefaz em cada ciclo.',
                    'default' => 250,
                    'unidade' => 'chaves',
                    'fronteira' => ['min' => 1, 'max' => 10000, 'tipo' => 'int'],
                ],
                'pausa_entre_chaves' => [
                    'nome' => 'Pausa entre chaves',
                    'ajuda' => 'Espera, em segundos, entre uma consulta à Sefaz e a seguinte.',
                    'default' => 1,
                    'unidade' => 'segundos',
                    'fronteira' => ['min' => 0, 'max' => 300, 'tipo' => 'int'],
                ],
                'intervalo_horas' => [
                    'nome' => 'Intervalo entre consultas da chave',
                    'ajuda' => 'Tempo mínimo, em horas, antes de uma mesma chave ser consultada de novo na Sefaz.',
                    'default' => 3,
                    'unidade' => 'horas',
                    'fronteira' => ['min' => 1, 'max' => 168, 'tipo' => 'int'],
                ],
                'dias_retencao' => [
                    'nome' => 'Retenção',
                    'ajuda' => 'Quantos dias as chaves ficam sob acompanhamento do robô.',
                    'default' => 73,
                    'unidade' => 'dias',
                    'fronteira' => ['min' => 1, 'max' => 3650, 'tipo' => 'int'],
                ],
                'gravar_a_cada' => [
                    'nome' => 'Gravar a cada',
                    'ajuda' => 'Quantas chaves consultadas são acumuladas antes de gravar o resultado no banco.',
                    'default' => 50,
                    'unidade' => 'chaves',
                    'fronteira' => ['min' => 1, 'max' => 1000, 'tipo' => 'int'],
                ],
                'semear_por_leitura' => [
                    'nome' => 'Linhas por leitura da semeadura',
                    'ajuda' => 'Quantas linhas de contingência são lidas de uma vez ao semear novas chaves.',
                    'default' => 2000,
                    'unidade' => 'linhas',
                    'fronteira' => ['min' => 100, 'max' => 50000, 'tipo' => 'int'],
                ],
                'semear_leituras_por_ciclo' => [
                    'nome' => 'Leituras da semeadura por ciclo',
                    'ajuda' => 'Máximo de leituras de semeadura feitas em cada ciclo.',
                    'default' => 20,
                    'unidade' => 'leituras',
                    'fronteira' => ['min' => 1, 'max' => 100, 'tipo' => 'int'],
                ],
                'pausa_consumo_indevido_minutos' => [
                    'nome' => 'Pausa após consumo indevido',
                    'ajuda' => 'Minutos em que o robô e a consulta manual ficam parados depois que a Sefaz devolve o código 656.',
                    'default' => 60,
                    'unidade' => 'minutos',
                    'fronteira' => ['min' => 1, 'max' => 1440, 'tipo' => 'int'],
                ],
            ],

            'sactus' => [
                'ativo' => [
                    'nome' => 'Robô ligado',
                    'ajuda' => 'Liga ou desliga a execução do robô neste servidor; só roda se o .env também permitir.',
                    'default' => false,
                    'unidade' => 'boolean',
                    'fronteira' => ['tipo' => 'bool'],
                ],
                'limite_ciclo_minutos' => [
                    'nome' => 'Duração máxima do ciclo',
                    'ajuda' => 'Tempo máximo, em minutos, que um ciclo pode durar antes de ser encerrado.',
                    'default' => 55,
                    'unidade' => 'minutos',
                    'fronteira' => ['min' => 1, 'max' => 1440, 'tipo' => 'int'],
                ],
                'clientes_ignorados' => [
                    'nome' => 'Clientes ignorados',
                    'ajuda' => 'Ids de clientes que o robô nunca visita.',
                    'default' => [18, 1470, 1718, 1896, 1898, 1908, 1943],
                    'unidade' => 'ids',
                    'fronteira' => ['tipo' => 'array'],
                ],
                'cfg_caminho' => [
                    'nome' => 'Caminho do sactus.cfg',
                    'ajuda' => 'Caminho do arquivo sactus.cfg no servidor do cliente.',
                    'default' => '/etc/sactus.cfg',
                    'unidade' => 'caminho',
                    'fronteira' => ['tipo' => 'string'],
                ],
                'alvo_estrito' => [
                    'nome' => 'Alvo estrito',
                    'ajuda' => 'Quando ligado, o robô tenta só o tipo de acesso do cliente (Docker ou normal), sem cair no outro como alternativa.',
                    'default' => false,
                    'unidade' => 'boolean',
                    'fronteira' => ['tipo' => 'bool'],
                ],
                'ssh.conexao_segundos' => [
                    'nome' => 'Tempo de conexão SSH',
                    'ajuda' => 'Tempo máximo, em segundos, para abrir a conexão SSH com o cliente.',
                    'default' => 10,
                    'unidade' => 'segundos',
                    'fronteira' => ['min' => 1, 'max' => 300, 'tipo' => 'int'],
                ],
                'ssh.comando_segundos' => [
                    'nome' => 'Tempo de comando SSH',
                    'ajuda' => 'Tempo máximo, em segundos, para um comando SSH responder.',
                    'default' => 30,
                    'unidade' => 'segundos',
                    'fronteira' => ['min' => 1, 'max' => 3600, 'tipo' => 'int'],
                ],
                'prechecagem.paralelo' => [
                    'nome' => 'Pré-checagem em paralelo',
                    'ajuda' => 'Quantos servidores são testados ao mesmo tempo na pré-checagem de porta.',
                    'default' => 50,
                    'unidade' => 'threads',
                    'fronteira' => ['min' => 1, 'max' => 200, 'tipo' => 'int'],
                ],
                'prechecagem.tcp_segundos' => [
                    'nome' => 'Tempo da pré-checagem',
                    'ajuda' => 'Tempo máximo, em segundos, para a porta do cliente responder na pré-checagem.',
                    'default' => 4,
                    'unidade' => 'segundos',
                    'fronteira' => ['min' => 1, 'max' => 60, 'tipo' => 'int'],
                ],
                'acl.ativo' => [
                    'nome' => 'ACL ligada',
                    'ajuda' => 'Liga a rotina que ajusta as permissões (ACL) dos arquivos no servidor do cliente.',
                    'default' => true,
                    'unidade' => 'boolean',
                    'fronteira' => ['tipo' => 'bool'],
                ],
                'acl.regras' => [
                    'nome' => 'Regras de ACL',
                    'ajuda' => 'Arquivos do cliente e o usuário que deve ter permissão sobre cada um.',
                    'default' => [
                        '/var/sistema/config.ini' => 'suporte',
                        '/etc/sactus.cfg' => 'suporte',
                    ],
                    'unidade' => 'regras',
                    'fronteira' => ['tipo' => 'array'],
                ],
                'acl.comando_segundos' => [
                    'nome' => 'Tempo de comando da ACL',
                    'ajuda' => 'Tempo máximo, em segundos, de cada comando de ACL no cliente.',
                    'default' => 30,
                    'unidade' => 'segundos',
                    'fronteira' => ['min' => 1, 'max' => 300, 'tipo' => 'int'],
                ],
                'acl.instalar_segundos' => [
                    'nome' => 'Tempo da instalação da ACL',
                    'ajuda' => 'Tempo máximo, em segundos, para instalar o pacote de ACL no cliente.',
                    'default' => 900,
                    'unidade' => 'segundos',
                    'fronteira' => ['min' => 1, 'max' => 3600, 'tipo' => 'int'],
                ],
                'erp.ativo' => [
                    'nome' => 'Atualização do ERP ligada',
                    'ajuda' => 'Liga a rotina que atualiza os arquivos do ERP no servidor do cliente.',
                    'default' => true,
                    'unidade' => 'boolean',
                    'fronteira' => ['tipo' => 'bool'],
                ],
                'erp.senha' => [
                    'nome' => 'Senha da origem do ERP',
                    'ajuda' => 'Senha para copiar os arquivos do ERP; o valor fica só no .env ou em mecanismo seguro e nunca aparece na tela.',
                    'default' => null,
                    'unidade' => 'texto',
                    'fronteira' => ['segredo' => true, 'tipo' => 'string'],
                    'requer_decisao_usuario' => true,
                ],
                'erp.origem' => [
                    'nome' => 'Origem do ERP',
                    'ajuda' => 'Servidor e pasta de onde os arquivos do ERP são copiados (usuário@host:caminho).',
                    'default' => 'root@192.168.10.220:/var/sistema/*',
                    'unidade' => 'caminho',
                    'fronteira' => ['tipo' => 'string'],
                ],
                'erp.porta_origem' => [
                    'nome' => 'Porta da origem do ERP',
                    'ajuda' => 'Porta SSH do servidor de origem dos arquivos do ERP.',
                    'default' => 22,
                    'unidade' => 'porta',
                    'fronteira' => ['min' => 1, 'max' => 65535, 'tipo' => 'int'],
                ],
                'erp.destino' => [
                    'nome' => 'Destino do ERP',
                    'ajuda' => 'Pasta do servidor do cliente que recebe os arquivos do ERP.',
                    'default' => '/var/sistema/',
                    'unidade' => 'caminho',
                    'fronteira' => ['tipo' => 'string'],
                ],
                'erp.depois' => [
                    'nome' => 'Comandos após o ERP',
                    'ajuda' => 'Comandos executados no cliente depois de copiar o ERP, um por linha.',
                    'default' => [
                        'chown -R apache.apache /var/sistema',
                        'chmod -R 777 /var/sistema',
                    ],
                    'unidade' => 'comandos',
                    'fronteira' => ['tipo' => 'array'],
                ],
                'erp.comando_segundos' => [
                    'nome' => 'Tempo do comando do ERP',
                    'ajuda' => 'Tempo máximo, em segundos, da cópia do ERP no cliente.',
                    'default' => 1800,
                    'unidade' => 'segundos',
                    'fronteira' => ['min' => 1, 'max' => 7200, 'tipo' => 'int'],
                ],
                'erp.saida_max' => [
                    'nome' => 'Saída máxima do ERP',
                    'ajuda' => 'Tamanho máximo, em bytes, da saída do comando do ERP guardada no histórico.',
                    'default' => 4000,
                    'unidade' => 'bytes',
                    'fronteira' => ['min' => 100, 'max' => 100000, 'tipo' => 'int'],
                ],
                'erp.vpn_prefixo' => [
                    'nome' => 'Prefixo da VPN',
                    'ajuda' => 'Início do endereço IP que identifica conexão pela VPN; a atualização do ERP exige esse IP.',
                    'default' => '10.8.0.',
                    'unidade' => 'prefixo',
                    'fronteira' => ['tipo' => 'string'],
                ],
                'cfg_edicoes.max_por_ciclo' => [
                    'nome' => 'Edições do cfg por ciclo',
                    'ajuda' => 'Máximo de edições pendentes do sactus.cfg aplicadas em cada ciclo.',
                    'default' => 20,
                    'unidade' => 'edicoes',
                    'fronteira' => ['min' => 1, 'max' => 500, 'tipo' => 'int'],
                ],
                'cfg_edicoes.trava_minutos' => [
                    'nome' => 'Trava de edição do cfg',
                    'ajuda' => 'Minutos em PROCESSANDO depois dos quais uma edição presa volta para a fila.',
                    'default' => 30,
                    'unidade' => 'minutos',
                    'fronteira' => ['min' => 1, 'max' => 1440, 'tipo' => 'int'],
                ],
                'cfg_edicoes.max_tentativas' => [
                    'nome' => 'Tentativas de edição do cfg',
                    'ajuda' => 'Quantas vezes uma edição do sactus.cfg é tentada antes de ser dada como falha.',
                    'default' => 3,
                    'unidade' => 'tentativas',
                    'fronteira' => ['min' => 1, 'max' => 10, 'tipo' => 'int'],
                ],
                'cfg_edicoes.backup_dir' => [
                    'nome' => 'Pasta do backup do cfg',
                    'ajuda' => 'Pasta do cliente onde fica a cópia do sactus.cfg antes de editar.',
                    'default' => '/home',
                    'unidade' => 'caminho',
                    'fronteira' => ['tipo' => 'string'],
                ],
                'cfg_edicoes.max_bytes' => [
                    'nome' => 'Tamanho máximo do cfg',
                    'ajuda' => 'Tamanho máximo, em bytes, do sactus.cfg aceito para edição.',
                    'default' => 1048576,
                    'unidade' => 'bytes',
                    'fronteira' => ['min' => 1024, 'max' => 10485760, 'tipo' => 'int'],
                ],
                'cfg_edicoes.tmp' => [
                    'nome' => 'Arquivo temporário do cfg',
                    'ajuda' => 'Caminho no cliente do arquivo temporário usado para enviar o novo sactus.cfg.',
                    'default' => '/tmp/sactus_cfg_edit',
                    'unidade' => 'caminho',
                    'fronteira' => ['tipo' => 'string'],
                ],
                'cfg_edicoes.pedaco' => [
                    'nome' => 'Tamanho do pedaço enviado',
                    'ajuda' => 'Tamanho, em bytes, de cada pedaço do arquivo enviado ao cliente na edição.',
                    'default' => 24576,
                    'unidade' => 'bytes',
                    'fronteira' => ['min' => 1024, 'max' => 1048576, 'tipo' => 'int'],
                ],
                'cfg_edicoes.comando_segundos' => [
                    'nome' => 'Tempo de comando da edição',
                    'ajuda' => 'Tempo máximo, em segundos, de cada comando usado na edição do sactus.cfg.',
                    'default' => 60,
                    'unidade' => 'segundos',
                    'fronteira' => ['min' => 1, 'max' => 600, 'tipo' => 'int'],
                ],
                'acoes' => [
                    'nome' => 'Ações disponíveis',
                    'ajuda' => 'Ações manuais permitidas no cliente, cada uma com o comando e o tempo máximo em segundos.',
                    'default' => [
                        'YUM_UPDATE' => ['comando' => 'yum -y update', 'segundos' => 1800],
                        'INSTALAR_ACL' => ['comando' => 'yum install -y acl', 'segundos' => 900],
                    ],
                    'unidade' => 'acoes',
                    'fronteira' => ['tipo' => 'array'],
                ],
                'obsoleto_minutos' => [
                    'nome' => 'Dado obsoleto após',
                    'ajuda' => 'Minutos sem coleta para o painel marcar o cliente como desatualizado.',
                    'default' => 60,
                    'unidade' => 'minutos',
                    'fronteira' => ['min' => 1, 'max' => 1440, 'tipo' => 'int'],
                ],
            ],

            'contingencias' => [
                'ativo' => [
                    'nome' => 'Robô ligado',
                    'ajuda' => 'Liga ou desliga a execução do robô neste servidor; só roda se o .env também permitir.',
                    'default' => false,
                    'unidade' => 'boolean',
                    'fronteira' => ['tipo' => 'bool'],
                ],
            ],

            'sentinela' => [
                'ativo' => [
                    'nome' => 'Robô ligado',
                    'ajuda' => 'Liga ou desliga a execução do robô neste servidor; só roda se o .env também permitir.',
                    'default' => false,
                    'unidade' => 'boolean',
                    'fronteira' => ['tipo' => 'bool'],
                ],
            ],

            'cleantax' => [
                'ativo' => [
                    'nome' => 'Robô ligado',
                    'ajuda' => 'Liga ou desliga a execução do robô neste servidor; só roda se o .env também permitir.',
                    'default' => false,
                    'unidade' => 'boolean',
                    'fronteira' => ['tipo' => 'bool'],
                ],
                'token' => [
                    'nome' => 'Token da CleanTax',
                    'ajuda' => 'Token de acesso à CleanTax; o valor fica só no .env ou em mecanismo seguro e nunca aparece na tela.',
                    'default' => null,
                    'unidade' => 'texto',
                    'fronteira' => ['segredo' => true, 'tipo' => 'string'],
                ],
                'hosts_seriais' => [
                    'nome' => 'Hosts seriais',
                    'ajuda' => 'Hosts em que as chamadas à CleanTax são feitas uma de cada vez, com intervalo de 3 segundos.',
                    'default' => ['177.136.203.233'],
                    'unidade' => 'hosts',
                    'fronteira' => ['tipo' => 'array'],
                ],
            ],

            'tarefas' => [
                'ativo' => [
                    'nome' => 'Robô ligado',
                    'ajuda' => 'Liga ou desliga a execução do robô neste servidor; só roda se o .env também permitir.',
                    'default' => false,
                    'unidade' => 'boolean',
                    'fronteira' => ['tipo' => 'bool'],
                ],
                'hora_inicio' => [
                    'nome' => 'Hora de início',
                    'ajuda' => 'Hora do dia (HH:MM:SS) a partir da qual o robô executa as tarefas.',
                    'default' => '00:00:01',
                    'unidade' => 'hora',
                    'fronteira' => ['tipo' => 'string'],
                ],
                'hora_fim' => [
                    'nome' => 'Hora de fim',
                    'ajuda' => 'Hora do dia (HH:MM:SS) até a qual o robô executa as tarefas.',
                    'default' => '23:59:59',
                    'unidade' => 'hora',
                    'fronteira' => ['tipo' => 'string'],
                ],
            ],
        ];
    }
}
