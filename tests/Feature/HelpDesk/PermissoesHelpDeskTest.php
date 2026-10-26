<?php

use App\Support\HelpDesk\ClassificadorSql;
use App\Support\HelpDesk\PermissoesHelpDesk;
use App\Support\HelpDesk\ResultadoPermissoes;
use App\Support\HelpDesk\TravaHelpDeskProducao;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
 * D13: as permissões reais do usuário da conexão "help" (SHOW GRANTS FOR CURRENT_USER).
 * SEGURANÇA: nenhum teste daqui abre conexão com o 192.168.10.254; o caso de falha usa
 * host de produção, porta 1 e um PDO que só lança exceção.
 */
test('interpreta o SHOW GRANTS', function (array $linhas, bool $somenteLeitura, array $privilegiosDeEscrita) {
    $resultado = app(PermissoesHelpDesk::class)->interpretar($linhas);

    expect($resultado->somenteLeitura)->toBe($somenteLeitura)
        ->and($resultado->privilegiosDeEscrita)->toBe($privilegiosDeEscrita)
        ->and($resultado->erro)->toBeNull();
})->with([
    'root do MySQL 5.x com ALL e hash da senha' => [
        ["GRANT ALL PRIVILEGES ON *.* TO 'root'@'%' IDENTIFIED BY PASSWORD '*ABC' WITH GRANT OPTION"],
        false, ['ALL PRIVILEGES', 'GRANT OPTION'],
    ],
    'USAGE com hash e SELECT no suporte' => [
        ["GRANT USAGE ON *.* TO 'walle'@'%' IDENTIFIED BY PASSWORD '*ABC'", "GRANT SELECT ON `suporte`.* TO 'walle'@'%'"],
        true, [],
    ],
    'SELECT, SHOW VIEW e SHOW DATABASES' => [
        ['GRANT SELECT, SHOW VIEW ON `suporte`.* TO `walle`@`%`', 'GRANT SHOW DATABASES ON *.* TO `walle`@`%`'],
        true, [],
    ],
    'privilégio de coluna com INSERT' => [
        ["GRANT SELECT (`id`, `name`), INSERT (`tx_senha`) ON `suporte`.`a_user` TO 'walle'@'%'"],
        false, ['INSERT'],
    ],
    'privilégio de coluna só com SELECT' => [
        ["GRANT SELECT (`id`, `nome, on x`) ON `suporte`.`a_user` TO 'walle'@'%'"],
        true, [],
    ],
    'SELECT com WITH GRANT OPTION' => [
        ["GRANT SELECT ON `suporte`.* TO 'walle'@'%' WITH GRANT OPTION"],
        false, ['GRANT OPTION'],
    ],
    'GRANT PROXY' => [
        ["GRANT PROXY ON ''@'' TO 'root'@'localhost' WITH GRANT OPTION"],
        false, ['PROXY', 'GRANT OPTION'],
    ],
    'papel (MySQL 8)' => [
        ['GRANT USAGE ON *.* TO `walle`@`%`', 'GRANT `leitura`@`%` TO `walle`@`%`'],
        false, ['PAPEL'],
    ],
    'privilégio dinâmico do MySQL 8' => [
        ['GRANT SELECT ON *.* TO `walle`@`%`', 'GRANT BACKUP_ADMIN ON *.* TO `walle`@`%`'],
        false, ['BACKUP_ADMIN'],
    ],
    'revogação parcial não concede nada' => [
        ['GRANT SELECT ON *.* TO `walle`@`%`', 'REVOKE SELECT ON `mysql`.* FROM `walle`@`%`'],
        true, [],
    ],
    'formato desconhecido' => [
        ['ALGO QUE NAO E GRANT segredo-xyz'],
        false, ['DESCONHECIDO'],
    ],
    'texto entre aspas aberto' => [
        ["GRANT SELECT ON `suporte.* TO 'walle'@'%'"],
        false, ['DESCONHECIDO'],
    ],
    'nenhuma linha' => [
        [],
        false, ['DESCONHECIDO'],
    ],
]);

test('o resultado serializado não guarda a linha bruta nem o hash da senha', function () {
    $resultado = app(PermissoesHelpDesk::class)->interpretar([
        "GRANT ALL PRIVILEGES ON *.* TO 'root'@'%' IDENTIFIED BY PASSWORD '*ABC' WITH GRANT OPTION",
    ]);

    $serializado = json_encode($resultado->toArray());

    expect($serializado)->not->toContain('*ABC')->not->toContain('IDENTIFIED')->not->toContain('root')
        ->and(ResultadoPermissoes::fromArray($resultado->toArray()))->toEqual($resultado);
});

test('verificar() no banco de teste passa pela trava fora dos testes e acusa que pode gravar', function () {
    (new TravaHelpDeskProducao(app(ClassificadorSql::class), emTestes: false))->proteger(DB::connection('help'));

    $resultado = app(PermissoesHelpDesk::class)->verificar();

    // O usuário walle tem ALL no banco de testes
    expect($resultado->erro)->toBeNull()
        ->and($resultado->somenteLeitura)->toBeFalse()
        ->and($resultado->privilegiosDeEscrita)->toContain('ALL PRIVILEGES');
});

test('verificar() guarda no cache só o resultado, pelo tempo do config', function () {
    config(['walle.help_desk.permissoes_cache_minutos' => 3]);
    DB::connection('help')->enableQueryLog();

    $primeiro = app(PermissoesHelpDesk::class)->verificar();
    $segundo = app(PermissoesHelpDesk::class)->verificar();

    expect(DB::connection('help')->getQueryLog())->toHaveCount(1)
        ->and($segundo)->toEqual($primeiro)
        ->and(json_encode(Cache::get('help:permissoes:help')))->not->toContain('GRANT ');

    $this->travel(4)->minutes();

    expect(Cache::get('help:permissoes:help'))->toBeNull();
});

test('verificar() com o Help Desk fora do ar devolve erro sem host nem SQL e não guarda no cache', function () {
    config(['database.connections.help_permissoes_teste' => array_merge(config('database.connections.mysql'), [
        'host' => '192.168.10.254',
        'port' => 1,
        'database' => 'nao_existe',
        'username' => 'guarda',
        'password' => 'x',
    ])]);
    /** @var Connection $conexao */
    $conexao = DB::connection('help_permissoes_teste');
    // Mensagem que o Laravel não confunde com conexão perdida (senão ele tentaria reconectar de verdade)
    $semRede = fn () => throw new LogicException('TENTOU CONECTAR em 192.168.10.254');
    $conexao->setPdo($semRede)->setReadPdo($semRede);

    $resultado = (new PermissoesHelpDesk('help_permissoes_teste'))->verificar();

    expect($resultado->somenteLeitura)->toBeFalse()
        ->and($resultado->erro)->toBeString()
        ->and($resultado->erro)->not->toContain('192.168.10.254')->not->toContain('SHOW GRANTS')
        ->and(Cache::get('help:permissoes:help_permissoes_teste'))->toBeNull();
});
