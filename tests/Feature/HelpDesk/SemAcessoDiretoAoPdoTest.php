<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\SplFileInfo;

/*
 * A trava do Help Desk (TravaHelpDeskProducao) roda no Connection::beforeExecuting(): quem usa o PDO
 * direto escapa dela. Só o conector de cliente abre o PDO de propósito (confere o acesso ao MySQL do
 * cliente, nunca o Help Desk).
 *
 * Também conta como uso direto abrir um PDO próprio: new PDO(, new \PDO(, new Pdo\Mysql( e PDO::connect( /
 * Pdo\Mysql::connect(. Sem diferenciar maiúsculas, como o PHP faz com nomes de classe e de método.
 * Constantes (PDO::FETCH_ASSOC, Pdo\Mysql::ATTR_SSL_CA) e a palavra PDO num comentário não contam.
 * (\x5C é a barra invertida do nome com namespace.)
 */
const USO_DIRETO_DO_PDO = '/\b(get|set)(Raw)?(Read)?Pdo\s*\('
    .'|\bnew\s+\x5C?Pdo(\x5C\w+)?\s*\('
    .'|(?<![\w\x5C])\x5C?Pdo(\x5C\w+)?\s*::\s*connect\s*\(/i';

test('nenhum arquivo PHP da pasta usa o PDO das conexões direto, fora do conector de cliente', function (string $pasta) {
    $permitido = realpath(app_path('Contingencias/Cliente/ConectorDeCliente.php'));

    $usamPdo = collect(File::allFiles(base_path($pasta)))
        ->filter(fn (SplFileInfo $arquivo): bool => $arquivo->getExtension() === 'php' && $arquivo->getRealPath() !== $permitido)
        ->filter(fn (SplFileInfo $arquivo): bool => preg_match(USO_DIRETO_DO_PDO, $arquivo->getContents()) === 1)
        ->map(fn (SplFileInfo $arquivo): string => $pasta.'/'.$arquivo->getRelativePathname())
        ->values()
        ->all();

    expect($usamPdo)->toBe([]);
})->with(['app', 'routes', 'database', 'config', 'bootstrap']);

test('a varredura reconhece o uso direto do PDO', function (string $codigo, bool $usaPdo) {
    expect(preg_match(USO_DIRETO_DO_PDO, $codigo) === 1)->toBe($usaPdo);
})->with([
    'getPdo' => ['DB::connection("help")->getPdo()->exec("delete from a_user");', true],
    'getReadPdo' => ['$conexao->getReadPdo ();', true],
    'getRawPdo' => ['$conexao->getRawPdo();', true],
    'setPdo' => ['$conexao->setPdo($pdo);', true],
    'new PDO' => ['$pdo = new PDO("mysql:host=192.168.10.254", "u", "s");', true],
    'new \PDO' => ['$pdo = new \PDO($dsn);', true],
    'new pdo em minúsculas' => ['$pdo = new pdo($dsn);', true],
    'new Pdo\Mysql' => ['$pdo = new Pdo\Mysql($dsn);', true],
    'new \Pdo\Mysql com quebra de linha' => ["\$pdo = new \\Pdo\\Mysql\n(\$dsn);", true],
    'new PDO\MYSQL em maiúsculas' => ['$pdo = new PDO\MYSQL($dsn);', true],
    'PDO::connect' => ['$pdo = PDO::connect($dsn);', true],
    '\PDO::connect' => ['$pdo = \PDO::connect($dsn);', true],
    'Pdo\Mysql::connect' => ['$pdo = Pdo\Mysql::connect($dsn);', true],
    '\pdo\mysql::CONNECT em minúsculas' => ['$pdo = \pdo\mysql::CONNECT($dsn);', true],
    'consulta pelo Laravel' => ['DB::connection("help")->table("a_user")->get();', false],
    'PDO só num comentário' => ['// o Laravel abre o PDO só na primeira consulta', false],
    'constante PDO::FETCH_ASSOC' => ['$modo = PDO::FETCH_ASSOC;', false],
    'constante \Pdo\Mysql::ATTR_SSL_CA' => ['[\Pdo\Mysql::ATTR_SSL_CA => $ca]', false],
    'outra classe com PDO no nome' => ['$x = new PDOStatement(); $y = MeuPdo::connect($dsn); $z = new Pacote\PDO($dsn);', false],
]);
