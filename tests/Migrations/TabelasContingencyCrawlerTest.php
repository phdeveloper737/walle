<?php

use App\Models\InformacaoTecnicaPainelContingencias;
use App\Models\PainelContingencyCrawler;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

/*
 * Colunas na ordem do SHOW CREATE TABLE do banco suporte (testes220).
 */
test('as tabelas do Contingência BOT têm as mesmas colunas do 220', function (string $tabela, array $colunas) {
    expect(Schema::getColumnListing($tabela))->toBe($colunas);
})->with([
    'painel_contingency_crawler' => ['painel_contingency_crawler', [
        'fk_c_cliente', 'total_cliente', 'total_bot', 'total_sactus', 'total_versatus', 'data',
    ]],
    'informacao_tecnica_painel_contingencias' => ['informacao_tecnica_painel_contingencias', [
        'id', 'fk_c_cliente', 'id_loja', 'cnpj', 'razao_social', 'nome_fantasia', 'tips_server', 'tips_pdv',
        'ip_docker_principal', 'banco_separado_docker', 'ip_container_docker', 'porta_http_docker',
        'porta_mysql_docker', 'porta_ssh_docker', 'porta_ssh_container_docker', 'senha_ssh_docker',
        'banco_mysql_docker', 'senha_mysql_docker', 'usuario_mysql_docker', 'ip_externo_servidor',
        'servidor_mysql', 'porta_http', 'porta_mysql', 'porta_ssh', 'banco_mysql', 'senha_mysql', 'senha_ssh',
        'ativo_painel_contingencias', 'deletado', 'ultima_atualizacao', 'ip_publico', 'emissao_cupons',
    ]],
]);

test('a métrica do robô de um cliente é gravada e lida pela chave fk_c_cliente', function () {
    PainelContingencyCrawler::create([
        'fk_c_cliente' => 42,
        'total_cliente' => 10,
        'total_bot' => 8,
        'total_sactus' => 5,
        'total_versatus' => 3,
        'data' => '2026-10-23 08:00:00',
    ]);

    $lida = PainelContingencyCrawler::findOrFail(42);

    expect($lida->only(['fk_c_cliente', 'total_cliente', 'total_bot', 'total_sactus', 'total_versatus']))->toBe([
        'fk_c_cliente' => 42,
        'total_cliente' => 10,
        'total_bot' => 8,
        'total_sactus' => 5,
        'total_versatus' => 3,
    ]);
    expect($lida->data->format('Y-m-d H:i:s'))->toBe('2026-10-23 08:00:00');
});

test('atualizar a métrica pelo model recém-criado altera a linha do cliente', function () {
    $painel = PainelContingencyCrawler::create(['fk_c_cliente' => 42, 'total_bot' => 8]);

    $painel->update(['total_bot' => 9]);

    $this->assertDatabaseHas('painel_contingency_crawler', ['fk_c_cliente' => 42, 'total_bot' => 9]);
});

test('a configuração técnica de um cliente é gravada com id igual ao do cliente e lida com os padrões do 220', function () {
    InformacaoTecnicaPainelContingencias::create([
        'id' => 42,
        'fk_c_cliente' => 42,
        'id_loja' => '001',
        'cnpj' => '12.345.678/0001-90',
        'razao_social' => 'MERCADO TESTE LTDA',
        'nome_fantasia' => 'MERCADO TESTE',
        'tips_server' => 'SA',
        'tips_pdv' => 'L',
        'ip_externo_servidor' => '200.1.2.3',
        'banco_mysql' => 'sactus',
        'senha_mysql' => 'senha-do-banco',
        'senha_ssh' => 'senha-ssh',
    ]);

    $lida = InformacaoTecnicaPainelContingencias::findOrFail(42);

    expect($lida->only([
        'id', 'fk_c_cliente', 'id_loja', 'cnpj', 'razao_social', 'nome_fantasia', 'tips_server', 'tips_pdv',
        'ip_externo_servidor', 'banco_mysql', 'senha_mysql', 'senha_ssh',
        'banco_separado_docker', 'porta_http_docker', 'porta_mysql_docker', 'porta_ssh_docker',
        'porta_http', 'porta_mysql', 'porta_ssh', 'ativo_painel_contingencias', 'deletado', 'emissao_cupons',
    ]))->toBe([
        'id' => 42,
        'fk_c_cliente' => 42,
        'id_loja' => '001',
        'cnpj' => '12.345.678/0001-90',
        'razao_social' => 'MERCADO TESTE LTDA',
        'nome_fantasia' => 'MERCADO TESTE',
        'tips_server' => 'SA',
        'tips_pdv' => 'L',
        'ip_externo_servidor' => '200.1.2.3',
        'banco_mysql' => 'sactus',
        'senha_mysql' => 'senha-do-banco',
        'senha_ssh' => 'senha-ssh',
        'banco_separado_docker' => 'N',
        'porta_http_docker' => '80',
        'porta_mysql_docker' => '3306',
        'porta_ssh_docker' => '22',
        'porta_http' => '80',
        'porta_mysql' => '3306',
        'porta_ssh' => '22',
        'ativo_painel_contingencias' => 'S',
        'deletado' => 'N',
        'emissao_cupons' => 'N',
    ]);
    expect($lida->ultima_atualizacao)->toBeInstanceOf(Carbon::class);
});

test('o mesmo cliente (fk_c_cliente) não é gravado duas vezes', function (string $modelo) {
    /** @var class-string<PainelContingencyCrawler|InformacaoTecnicaPainelContingencias> $modelo */
    $modelo::create(['fk_c_cliente' => 42]);

    expect(fn () => $modelo::create(['fk_c_cliente' => 42]))->toThrow(UniqueConstraintViolationException::class);
    expect($modelo::where('fk_c_cliente', 42)->count())->toBe(1);
})->with([
    'configuração técnica (informacao_tecnica_painel_contingencias)' => InformacaoTecnicaPainelContingencias::class,
    'métrica do robô (painel_contingency_crawler)' => PainelContingencyCrawler::class,
]);
