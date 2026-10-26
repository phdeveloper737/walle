<?php

use App\Support\HelpDesk\TravaHelpDeskProducao;
use Illuminate\Support\Facades\DB;

/*
 * Nos testes a conexão "help" aponta para `<banco de testes>_help`, nunca para o banco principal nem para o
 * Help Desk real (tests/TestCase.php). SEGURANÇA: nada daqui abre conexão com o 192.168.10.254, e
 * migrateDatabases() só é chamado com nomes inválidos, que ele recusa antes de qualquer SQL.
 */

test('nos testes, a conexão help usa o banco <banco de testes>_help', function () {
    $principal = DB::connection('mysql')->getDatabaseName();

    expect($principal)->toMatch('/^walle_testes(_t[0-9]+)?\z/')
        ->and(DB::connection('help')->getDatabaseName())->toBe($principal.'_help');
});

test('nos testes, a conexão help não aponta para nenhum host do Help Desk de produção', function () {
    expect(config('walle.help_desk.hosts_producao'))->not->toBeEmpty()
        ->and(TravaHelpDeskProducao::hostDeProducao(DB::connection('help')->getConfig()))->toBeNull();
});

test('nos testes, a conexão auditoria usa o banco de testes do walle, em transação, e nunca um host de produção', function () {
    $auditoria = DB::connection('auditoria');

    expect($auditoria->transactionLevel())->toBeGreaterThanOrEqual(1)
        ->and($auditoria->getDatabaseName())->toBe(DB::connection('mysql')->getDatabaseName())
        ->and(TravaHelpDeskProducao::hostDeProducao($auditoria->getConfig()))->toBeNull();
});

/*
 * O tests/TestCase.php sobrescreve a conexão "auditoria" com a config da "mysql", então os testes acima não
 * enxergam o arquivo real. Este lê o config/database.php de verdade e compara só as arrays entre si: a
 * mensagem de falha não mostra host, usuário nem senha.
 */
test('no config/database.php real, a conexão auditoria é idêntica à mysql', function () {
    /** @var array{connections: array<string, mixed>} $config */
    $config = require config_path('database.php');
    $conexoes = $config['connections'];

    expect(is_array($conexoes['mysql'] ?? null))->toBeTrue()
        ->and(array_key_exists('auditoria', $conexoes))->toBeTrue()
        ->and($conexoes['auditoria'] === $conexoes['mysql'])->toBeTrue();
});

test('exigirBancoDeTeste aceita só walle_testes e walle_testes_tNN, sem nada depois', function (string $banco, bool $aceito) {
    $exigir = fn () => $this->exigirBancoDeTeste($banco);

    if ($aceito) {
        expect($exigir)->not->toThrow(RuntimeException::class);
    } else {
        expect($exigir)->toThrow(RuntimeException::class, 'Banco de testes inválido');
    }
})->with([
    'walle_testes' => ['walle_testes', true],
    'walle_testes_t07' => ['walle_testes_t07', true],
    'walle_testes com quebra de linha no fim' => ["walle_testes\n", false],
    'walle_testes_t07 com quebra de linha no fim' => ["walle_testes_t07\n", false],
]);

test('migrateDatabases recusa banco fora do padrão walle_testes antes de criar qualquer banco', function (string $banco) {
    $bancosAntes = DB::connection('mysql')->table('information_schema.SCHEMATA')->orderBy('SCHEMA_NAME')->pluck('SCHEMA_NAME')->all();
    config(['database.connections.mysql.database' => $banco]);

    expect(fn () => $this->migrateDatabases())
        ->toThrow(RuntimeException::class, "Banco de testes inválido: \"{$banco}\". Use walle_testes ou walle_testes_tNN.");

    $bancosDepois = DB::connection('mysql')->table('information_schema.SCHEMATA')->orderBy('SCHEMA_NAME')->pluck('SCHEMA_NAME')->all();

    expect($bancosDepois)->toBe($bancosAntes)
        ->and(DB::connection('mysql')->table('information_schema.SCHEMATA')->where('SCHEMA_NAME', $banco.'_help')->exists())->toBeFalse();
})->with([
    'banco de desenvolvimento' => 'walle',
    'Help Desk' => 'suporte',
    'banco help dos testes' => 'walle_testes_help',
    'sufixo solto' => 'walle_testesx',
    'sessão paralela sem número' => 'walle_testes_t',
    'sessão paralela com sufixo' => 'walle_testes_t01_help',
    'maiúsculas' => 'WALLE_TESTES',
    'vazio' => '',
]);
