<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\DatabaseManager;
use Tests\Support\PreparadorDeBancosDeTeste;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

/** @var DatabaseManager $databaseManager */
$databaseManager = $app->make('db');
$configuracao = config('database.connections.mysql');

if (! is_array($configuracao)) {
    throw new RuntimeException('A conexão mysql não está configurada.');
}

(new PreparadorDeBancosDeTeste($databaseManager))->preparar($configuracao);
