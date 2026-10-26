<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\SplFileInfo;

/*
 * A trava do Help Desk (TravaHelpDeskProducao) roda no Connection::beforeExecuting(): quem usa o PDO
 * direto escapa dela. Só o conector de cliente abre o PDO de propósito (confere o acesso ao MySQL do
 * cliente, nunca o Help Desk).
 */
const USO_DIRETO_DO_PDO = '/\b(get|set)(Raw)?(Read)?Pdo\s*\(/';

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
    'consulta pelo Laravel' => ['DB::connection("help")->table("a_user")->get();', false],
]);
