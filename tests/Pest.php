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
