<?php

use App\Contingencias\Cliente\ConectorDeCliente;
use App\Contingencias\Cliente\ConexaoComCliente;
use App\Contingencias\Cliente\FalhaConexaoCliente;
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
        'loja' => '1',
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

/** A "conexão com o servidor do cliente" aponta para o próprio banco de testes, onde ficam as imitações do Sactus. */
function servidorDoClienteNoBancoDeTestes(): void
{
    test()->mock(ConectorDeCliente::class)
        ->shouldReceive('conectar')
        ->andReturn(new ConexaoComCliente(DB::connection(), DB::getDatabaseName(), fecharAoTerminar: false));
}

function servidorDoClienteForaDoAr(): void
{
    test()->mock(ConectorDeCliente::class)
        ->shouldReceive('conectar')
        ->andThrow(FalhaConexaoCliente::servidorNaoResponde('teste'));
}

/**
 * Venda do Versatus em contingência (banco walle) e os itens dela; devolve o id da venda.
 *
 * @param  array<string, mixed>  $venda
 * @param  list<array<string, mixed>>  $itens
 */
function criarContingenciaVersatus(int $clienteId, array $venda = [], array $itens = [[]]): int
{
    $id = (int) DB::table('contingencias_versatus_venda')->insertGetId(array_merge([
        'fk_contingencias_versatus' => $clienteId,
        'fk_company' => 1,
        'pdv' => 1,
        'cupom' => '100',
        'data_hora' => '2026-10-20 10:00:00',
        'codigo' => '781',
        'motivo' => 'Rejeição: Emissor não habilitado para emissão da NFC-e',
        'chave' => str_repeat('1', 44),
    ], $venda));

    foreach ($itens as $item) {
        DB::table('contingencias_versatus_venda_itens')->insert(array_merge([
            'fk_contingencias_versatus_venda' => $id,
            'cod_barras' => '7890000000001',
            'fk_produto' => 10,
            'nome' => 'ARROZ 5KG',
            'unidade' => 'UN',
            'aliquota' => 18,
            'base_reducao' => 0,
            'aliquota_reduzida' => 0,
            'ncm' => '10063021',
            'cest' => '',
            'cbenef' => '',
            'cfop' => '5102',
            'cst' => '000',
        ], $item));
    }

    return $id;
}

/**
 * Contingência do Sactus no "servidor do cliente" (imitação no banco de testes), com a venda e os itens.
 *
 * @param  array<string, mixed>  $cabecalho
 * @param  list<array<string, mixed>>  $itens
 */
function criarContingenciaSactus(array $cabecalho = [], array $itens = [[]], ?string $chave = null, bool $chaveNaContingencia = true): string
{
    $cab = array_merge([
        'store' => '1',
        'pdv' => '2',
        'cupom' => '500',
        'fiscal_date' => '2026-10-19 09:00:00',
        'cod_motivo' => '382',
        'motivo' => 'Rejeição: CFOP não permitido para o CST informado',
    ], $cabecalho);

    $chave ??= str_pad((string) $cab['cupom'], 44, '2', STR_PAD_LEFT);

    DB::table('contingencias_pdv')->insert([...$cab, 'chave_acesso_nfce' => $chaveNaContingencia ? $chave : null]);
    DB::table('sale')->insert([
        'store' => $cab['store'], 'pdv' => $cab['pdv'], 'cupom' => $cab['cupom'],
        'fiscal_date' => $cab['fiscal_date'], 'chave_acesso_nfce' => $chave,
    ]);

    foreach (array_values($itens) as $seq => $item) {
        DB::table('sale_itens')->insert(array_merge([
            'store' => $cab['store'], 'pdv' => $cab['pdv'], 'cupom' => $cab['cupom'],
            'fiscal_date' => $cab['fiscal_date'], 'seq' => $seq + 1,
            'sku' => '7890000000002', 'plu_id' => '20', 'plu_sdesc' => 'FEIJAO 1KG', 'unit' => 'UN',
            'aliquota_cheia' => '7.000', 'reducao_base' => '0.000', 'taxid' => 'T07',
            'ncm' => '07133319', 'cest' => '', 'cbenef' => '', 'cfop' => '5102', 'cst_icms' => '0',
            'cst_ibscbs' => '000', 'classtrib_ibs' => '000001',
        ], $item));
    }

    return $chave;
}

function autorizarNaSefaz(int $clienteId, string $chave, string $referencia): void
{
    DB::table('history_contingencia_auth')->insert([
        'fk_c_cliente' => $clienteId,
        'chave' => $chave,
        'autorizada' => 'S',
        'referencia' => $referencia,
        'data_consulta_sefaz_bot' => now(),
    ]);
}
