<?php

namespace App\Support;

/**
 * Catálogo de parâmetros configuráveis de todos os robôs do walle.
 * Define defaults, unidades, fronteiras de validação (min, max, tipo, segredo) e se requer decisão do usuário.
 */
class ParametroCatalogo
{
    /**
     * Retorna o catálogo completo de todos os robôs.
     *
     * @return array<string, array<string, array{default: mixed, unidade: string, fronteira: array<string, mixed>, requer_decisao_usuario?: bool}>>
     */
    public static function todos(): array
    {
        return [
            'autorizadas' => [
                'ativo' => [
                    'default' => false,
                    'unidade' => 'boolean',
                    'fronteira' => ['tipo' => 'bool'],
                ],
                'limite_ciclo' => [
                    'default' => 250,
                    'unidade' => 'chaves',
                    'fronteira' => ['min' => 1, 'max' => 10000, 'tipo' => 'int'],
                ],
                'pausa_entre_chaves' => [
                    'default' => 1,
                    'unidade' => 'segundos',
                    'fronteira' => ['min' => 0, 'max' => 300, 'tipo' => 'int'],
                ],
                'intervalo_horas' => [
                    'default' => 3,
                    'unidade' => 'horas',
                    'fronteira' => ['min' => 1, 'max' => 168, 'tipo' => 'int'],
                ],
                'dias_retencao' => [
                    'default' => 73,
                    'unidade' => 'dias',
                    'fronteira' => ['min' => 1, 'max' => 3650, 'tipo' => 'int'],
                ],
                'gravar_a_cada' => [
                    'default' => 50,
                    'unidade' => 'chaves',
                    'fronteira' => ['min' => 1, 'max' => 1000, 'tipo' => 'int'],
                ],
                'semear_por_leitura' => [
                    'default' => 2000,
                    'unidade' => 'linhas',
                    'fronteira' => ['min' => 100, 'max' => 50000, 'tipo' => 'int'],
                ],
                'semear_leituras_por_ciclo' => [
                    'default' => 20,
                    'unidade' => 'leituras',
                    'fronteira' => ['min' => 1, 'max' => 100, 'tipo' => 'int'],
                ],
                'pausa_consumo_indevido_minutos' => [
                    'default' => 60,
                    'unidade' => 'minutos',
                    'fronteira' => ['min' => 1, 'max' => 1440, 'tipo' => 'int'],
                ],
            ],

            'sactus' => [
                'ativo' => [
                    'default' => false,
                    'unidade' => 'boolean',
                    'fronteira' => ['tipo' => 'bool'],
                ],
                'limite_ciclo_minutos' => [
                    'default' => 55,
                    'unidade' => 'minutos',
                    'fronteira' => ['min' => 1, 'max' => 1440, 'tipo' => 'int'],
                ],
                'clientes_ignorados' => [
                    'default' => [18, 1470, 1718, 1896, 1898, 1908, 1943],
                    'unidade' => 'ids',
                    'fronteira' => ['tipo' => 'array'],
                ],
                'cfg_caminho' => [
                    'default' => '/etc/sactus.cfg',
                    'unidade' => 'caminho',
                    'fronteira' => ['tipo' => 'string'],
                ],
                'alvo_estrito' => [
                    'default' => false,
                    'unidade' => 'boolean',
                    'fronteira' => ['tipo' => 'bool'],
                ],
                'ssh.conexao_segundos' => [
                    'default' => 10,
                    'unidade' => 'segundos',
                    'fronteira' => ['min' => 1, 'max' => 300, 'tipo' => 'int'],
                ],
                'ssh.comando_segundos' => [
                    'default' => 30,
                    'unidade' => 'segundos',
                    'fronteira' => ['min' => 1, 'max' => 3600, 'tipo' => 'int'],
                ],
                'prechecagem.paralelo' => [
                    'default' => 50,
                    'unidade' => 'threads',
                    'fronteira' => ['min' => 1, 'max' => 200, 'tipo' => 'int'],
                ],
                'prechecagem.tcp_segundos' => [
                    'default' => 4,
                    'unidade' => 'segundos',
                    'fronteira' => ['min' => 1, 'max' => 60, 'tipo' => 'int'],
                ],
                'acl.ativo' => [
                    'default' => true,
                    'unidade' => 'boolean',
                    'fronteira' => ['tipo' => 'bool'],
                ],
                'acl.regras' => [
                    'default' => [
                        '/var/sistema/config.ini' => 'suporte',
                        '/etc/sactus.cfg' => 'suporte',
                    ],
                    'unidade' => 'regras',
                    'fronteira' => ['tipo' => 'array'],
                ],
                'acl.comando_segundos' => [
                    'default' => 30,
                    'unidade' => 'segundos',
                    'fronteira' => ['min' => 1, 'max' => 300, 'tipo' => 'int'],
                ],
                'acl.instalar_segundos' => [
                    'default' => 900,
                    'unidade' => 'segundos',
                    'fronteira' => ['min' => 1, 'max' => 3600, 'tipo' => 'int'],
                ],
                'erp.ativo' => [
                    'default' => true,
                    'unidade' => 'boolean',
                    'fronteira' => ['tipo' => 'bool'],
                ],
                'erp.senha' => [
                    'default' => null,
                    'unidade' => 'texto',
                    'fronteira' => ['segredo' => true, 'tipo' => 'string'],
                    'requer_decisao_usuario' => true,
                ],
                'erp.origem' => [
                    'default' => 'root@192.168.10.220:/var/sistema/*',
                    'unidade' => 'caminho',
                    'fronteira' => ['tipo' => 'string'],
                ],
                'erp.porta_origem' => [
                    'default' => 22,
                    'unidade' => 'porta',
                    'fronteira' => ['min' => 1, 'max' => 65535, 'tipo' => 'int'],
                ],
                'erp.destino' => [
                    'default' => '/var/sistema/',
                    'unidade' => 'caminho',
                    'fronteira' => ['tipo' => 'string'],
                ],
                'erp.depois' => [
                    'default' => [
                        'chown -R apache.apache /var/sistema',
                        'chmod -R 777 /var/sistema',
                    ],
                    'unidade' => 'comandos',
                    'fronteira' => ['tipo' => 'array'],
                ],
                'erp.comando_segundos' => [
                    'default' => 1800,
                    'unidade' => 'segundos',
                    'fronteira' => ['min' => 1, 'max' => 7200, 'tipo' => 'int'],
                ],
                'erp.saida_max' => [
                    'default' => 4000,
                    'unidade' => 'bytes',
                    'fronteira' => ['min' => 100, 'max' => 100000, 'tipo' => 'int'],
                ],
                'erp.vpn_prefixo' => [
                    'default' => '10.8.0.',
                    'unidade' => 'prefixo',
                    'fronteira' => ['tipo' => 'string'],
                ],
                'cfg_edicoes.max_por_ciclo' => [
                    'default' => 20,
                    'unidade' => 'edicoes',
                    'fronteira' => ['min' => 1, 'max' => 500, 'tipo' => 'int'],
                ],
                'cfg_edicoes.trava_minutos' => [
                    'default' => 30,
                    'unidade' => 'minutos',
                    'fronteira' => ['min' => 1, 'max' => 1440, 'tipo' => 'int'],
                ],
                'cfg_edicoes.max_tentativas' => [
                    'default' => 3,
                    'unidade' => 'tentativas',
                    'fronteira' => ['min' => 1, 'max' => 10, 'tipo' => 'int'],
                ],
                'cfg_edicoes.backup_dir' => [
                    'default' => '/home',
                    'unidade' => 'caminho',
                    'fronteira' => ['tipo' => 'string'],
                ],
                'cfg_edicoes.max_bytes' => [
                    'default' => 1048576,
                    'unidade' => 'bytes',
                    'fronteira' => ['min' => 1024, 'max' => 10485760, 'tipo' => 'int'],
                ],
                'cfg_edicoes.tmp' => [
                    'default' => '/tmp/sactus_cfg_edit',
                    'unidade' => 'caminho',
                    'fronteira' => ['tipo' => 'string'],
                ],
                'cfg_edicoes.pedaco' => [
                    'default' => 24576,
                    'unidade' => 'bytes',
                    'fronteira' => ['min' => 1024, 'max' => 1048576, 'tipo' => 'int'],
                ],
                'cfg_edicoes.comando_segundos' => [
                    'default' => 60,
                    'unidade' => 'segundos',
                    'fronteira' => ['min' => 1, 'max' => 600, 'tipo' => 'int'],
                ],
                'acoes' => [
                    'default' => [
                        'YUM_UPDATE' => ['comando' => 'yum -y update', 'segundos' => 1800],
                        'INSTALAR_ACL' => ['comando' => 'yum install -y acl', 'segundos' => 900],
                    ],
                    'unidade' => 'acoes',
                    'fronteira' => ['tipo' => 'array'],
                ],
                'obsoleto_minutos' => [
                    'default' => 60,
                    'unidade' => 'minutos',
                    'fronteira' => ['min' => 1, 'max' => 1440, 'tipo' => 'int'],
                ],
            ],

            'contingencias' => [
                'ativo' => [
                    'default' => false,
                    'unidade' => 'boolean',
                    'fronteira' => ['tipo' => 'bool'],
                ],
            ],

            'sentinela' => [
                'ativo' => [
                    'default' => false,
                    'unidade' => 'boolean',
                    'fronteira' => ['tipo' => 'bool'],
                ],
            ],

            'cleantax' => [
                'ativo' => [
                    'default' => false,
                    'unidade' => 'boolean',
                    'fronteira' => ['tipo' => 'bool'],
                ],
                'token' => [
                    'default' => null,
                    'unidade' => 'texto',
                    'fronteira' => ['segredo' => true, 'tipo' => 'string'],
                ],
                'hosts_seriais' => [
                    'default' => ['177.136.203.233'],
                    'unidade' => 'hosts',
                    'fronteira' => ['tipo' => 'array'],
                ],
            ],

            'tarefas' => [
                'ativo' => [
                    'default' => false,
                    'unidade' => 'boolean',
                    'fronteira' => ['tipo' => 'bool'],
                ],
                'hora_inicio' => [
                    'default' => '00:00:01',
                    'unidade' => 'hora',
                    'fronteira' => ['tipo' => 'string'],
                ],
                'hora_fim' => [
                    'default' => '23:59:59',
                    'unidade' => 'hora',
                    'fronteira' => ['tipo' => 'string'],
                ],
            ],
        ];
    }
}
