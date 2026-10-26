<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\SplFileInfo;

/*
 * A trava do Help Desk (TravaHelpDeskProducao) roda no Connection::beforeExecuting(): quem usa o PDO
 * direto escapa dela. Só o conector de cliente abre o PDO de propósito (confere o acesso ao MySQL do
 * cliente, nunca o Help Desk).
 */
test('nenhum arquivo de app/ usa o PDO das conexões direto, fora do conector de cliente', function () {
    $permitido = realpath(app_path('Contingencias/Cliente/ConectorDeCliente.php'));

    $usamPdo = collect(File::allFiles(app_path()))
        ->filter(fn (SplFileInfo $arquivo): bool => $arquivo->getExtension() === 'php' && $arquivo->getRealPath() !== $permitido)
        ->filter(fn (SplFileInfo $arquivo): bool => preg_match('/\b(get|set)(Raw)?(Read)?Pdo\s*\(/', $arquivo->getContents()) === 1)
        ->map(fn (SplFileInfo $arquivo): string => $arquivo->getRelativePathname())
        ->values()
        ->all();

    expect($usamPdo)->toBe([]);
});
