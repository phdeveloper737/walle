<?php

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Os testes de Feature usam o Tests\TestCase: banco walle_testes zerado e a conexão "help"
| apontando para ele (nunca para o Help Desk de verdade).
|
*/

pest()->extend(TestCase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Funções de apoio
|--------------------------------------------------------------------------
*/

/**
 * Cria um usuário na imitação do a_user do Help Desk e devolve o id.
 *
 * @param  array<string, mixed>  $dados
 */
function criarUsuarioHelpDesk(array $dados = []): int
{
    return (int) DB::connection('help')->table('a_user')->insertGetId(array_merge([
        'name' => 'Maria da Silva',
        'user' => 'maria',
        'tx_senha' => 'segredo',
        'deletado' => 'N',
    ], $dados));
}

/**
 * Cria um cliente no Help Desk de teste (c_cliente + cadastro do servidor + contingências) e devolve o id.
 *
 * @param  array<string, mixed>  $cliente  colunas do c_cliente
 * @param  array<string, mixed>  $servidor  colunas do informacao_tecnica_dataserver
 */
function criarClienteHelpDesk(array $cliente = [], array $servidor = [], int $contingenciasLinux = 0, int $contingenciasVersatus = 0): int
{
    $help = DB::connection('help');

    $id = (int) $help->table('c_cliente')->insertGetId(array_merge([
        'razao_social' => 'MERCADO TESTE LTDA',
        'cnpj' => '12.345.678/0001-90',
        'consultoria' => 'N',
        'deletado' => 'N',
    ], $cliente));

    $help->table('informacao_tecnica_dataserver')->insert(array_merge([
        'fk_c_cliente' => $id,
        'loja' => '001',
        'ip_externo_servidor' => '200.1.2.3',
        'servidor_multiempresa' => 'S',
        'pasta_multiempresa' => 'N',
        'porta_http' => '8080',
        'local_servidor' => 'OPEN',
    ], $servidor));

    if ($contingenciasLinux > 0) {
        $help->table('informacao_tecnica_pdv_linux')->insert(['fk_c_cliente' => $id, 'contingencias' => $contingenciasLinux]);
    }

    if ($contingenciasVersatus > 0) {
        $help->table('informacao_atua_versatus')->insert(['fk_c_cliente' => $id, 'contigencia' => (string) $contingenciasVersatus]);
    }

    return $id;
}
