<?php

use App\Models\Auditoria;
use App\Models\Usuario;
use App\Support\HelpDesk\ClassificadorSql;
use App\Support\HelpDesk\EscritaNoHelpDeskDeProducao;
use App\Support\HelpDesk\TravaHelpDeskProducao;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\SplFileInfo;

/*
 * D12: a conexão "help" (e as da lista walle.help_desk.conexoes_somente_leitura) só lê fora dos testes,
 * qualquer que seja o host. SEGURANÇA: nenhum teste daqui abre conexão com o 192.168.10.254.
 * A conexão "pelo nome" aponta para 127.0.0.1 na porta 1 e tem o PDO trocado por um que só lança
 * exceção: se a trava deixasse passar, o teste veria "TENTOU CONECTAR".
 */
const CONEXAO_HELP_PELO_NOME = 'help_pelo_nome_teste';

function travaForaDosTestes(): TravaHelpDeskProducao
{
    return new TravaHelpDeskProducao(app(ClassificadorSql::class), emTestes: false);
}

/** Conexão com nome na lista de somente leitura (por padrão com host fora da lista de produção), sem rede. */
function conexaoHelpPeloNome(?TravaHelpDeskProducao $trava = null, string $banco = 'nao_existe', string $host = '127.0.0.1'): Connection
{
    config([
        'walle.help_desk.conexoes_somente_leitura' => ['help', CONEXAO_HELP_PELO_NOME],
        'database.connections.'.CONEXAO_HELP_PELO_NOME => array_merge(config('database.connections.mysql'), [
            'host' => $host,
            'port' => 1,
            'database' => $banco,
            'username' => 'guarda',
            'password' => 'x',
        ]),
    ]);

    /** @var Connection $conexao */
    $conexao = DB::connection(CONEXAO_HELP_PELO_NOME);
    $semRede = fn () => throw new LogicException('TENTOU CONECTAR');
    $conexao->setPdo($semRede)->setReadPdo($semRede);

    ($trava ?? travaForaDosTestes())->proteger($conexao);

    return $conexao;
}

test('fora dos testes, a conexão da lista barra insert, update e delete mesmo com host fora da produção', function (Closure $escrever) {
    $conexao = conexaoHelpPeloNome();

    expect(fn () => $escrever($conexao))->toThrow(
        EscritaNoHelpDeskDeProducao::class,
        'O Help Desk de produção (127.0.0.1) é somente consulta: o walle não grava nada lá.',
    );
})->with([
    'T06 insert' => [fn (Connection $c) => $c->table('a_user')->insert(['name' => 'x'])],
    'T07 update' => [fn (Connection $c) => $c->table('a_user')->where('id', 1)->update(['tx_senha' => 'x'])],
    'T08 delete' => [fn (Connection $c) => $c->table('a_user')->where('id', 1)->delete()],
]);

test('T09: fora dos testes, a conexão da lista barra toda instrução que não é leitura', function (Closure $escrever) {
    $conexao = conexaoHelpPeloNome();
    travaForaDosTestes()->proteger(DB::connection('help'));

    expect(fn () => $escrever($conexao))->toThrow(EscritaNoHelpDeskDeProducao::class);
})->with([
    'REPLACE' => [fn (Connection $c) => $c->affectingStatement('replace into a_user (id, name) values (1, ?)', ['x'])],
    'TRUNCATE' => [fn (Connection $c) => $c->table('a_user')->truncate()],
    'ALTER' => [fn (Connection $c) => $c->statement('alter table a_user add x int')],
    'DROP' => [fn (Connection $c) => $c->unprepared('DROP TABLE a_user')],
    'CREATE' => [fn (Connection $c) => $c->statement('create table x (id int)')],
    'RENAME' => [fn (Connection $c) => $c->statement('rename table a_user to b_user')],
    'CALL' => [fn (Connection $c) => $c->statement('call procedimento()')],
    'SET' => [fn (Connection $c) => $c->statement('set @x = 1')],
    'SET TRANSACTION READ ONLY' => [fn (Connection $c) => $c->statement('SET TRANSACTION READ ONLY')],
    'LOAD DATA' => [fn (Connection $c) => $c->statement("load data infile '/tmp/x' into table a_user")],
    'GRANT' => [fn (Connection $c) => $c->statement("grant all on *.* to 'x'@'%'")],
    'LOCK TABLES' => [fn (Connection $c) => $c->unprepared('lock tables a_user write')],
    'HANDLER' => [fn (Connection $c) => $c->unprepared('handler a_user open')],
    'DO' => [fn (Connection $c) => $c->statement('do sleep(1)')],
    'SELECT INTO OUTFILE' => [fn (Connection $c) => $c->select("select * from a_user into outfile '/tmp/x'")],
    'Schema builder' => [fn (Connection $c) => $c->getSchemaBuilder()->create('tabela_nova', fn (Blueprint $t) => $t->id())],
    'Usuario::query()->update()' => [fn () => Usuario::query()->update(['name' => 'Intruso'])],
]);

test('T01: fora dos testes, a leitura passa na conexão help e a escrita é barrada mesmo sem a lista no config', function (mixed $lista) {
    $id = criarUsuarioHelpDesk();
    $helpDesk = config('walle.help_desk');
    unset($helpDesk['conexoes_somente_leitura']);
    config(['walle.help_desk' => $lista === 'ausente' ? $helpDesk : [...$helpDesk, 'conexoes_somente_leitura' => $lista]]);
    travaForaDosTestes()->proteger(DB::connection('help'));

    expect(DB::connection('help')->table('a_user')->where('id', $id)->value('name'))->toBe('Maria da Silva')
        ->and(Usuario::query()->whereKey($id)->exists())->toBeTrue()
        ->and(fn () => DB::connection('help')->table('a_user')->where('id', $id)->update(['name' => 'Outro']))
        ->toThrow(EscritaNoHelpDeskDeProducao::class);

    expect(DB::connection('help')->table('a_user')->where('id', $id)->value('name'))->toBe('Maria da Silva');
})->with([
    'chave ausente (padrão)' => 'ausente',
    'lista vazia' => [[]],
    'lista sem a help' => [['outra']],
]);

test('fora dos testes, a leitura na conexão da lista passa pela trava (só para no conectar falso)', function () {
    $conexao = conexaoHelpPeloNome();

    expect(fn () => $conexao->table('a_user')->where('user', 'maria')->first())
        ->toThrow(QueryException::class, 'TENTOU CONECTAR');
});

test('nos testes, a trava pelo nome barra a conexão da lista quando o banco não é o de teste do Help Desk', function (string $banco) {
    $conexao = conexaoHelpPeloNome(new TravaHelpDeskProducao(app(ClassificadorSql::class), emTestes: true), $banco);

    expect(fn () => $conexao->insert('insert into a_user (name) values (?)', ['x']))
        ->toThrow(EscritaNoHelpDeskDeProducao::class);
})->with([
    'banco qualquer' => 'nao_existe',
    'Help Desk' => 'suporte',
    'banco de desenvolvimento' => 'walle',
    'banco de testes do walle' => 'walle_testes',
    'sufixo depois do _help' => 'walle_testes_help_x',
    'sessão paralela sem número' => 'walle_testes_t_help',
    'maiúsculas' => 'WALLE_TESTES_HELP',
    'quebra de linha no fim' => "walle_testes_help\n",
    'vazio' => '',
]);

test('nos testes, a trava pelo nome libera a conexão da lista só no banco de teste do Help Desk', function (string $banco) {
    $conexao = conexaoHelpPeloNome(new TravaHelpDeskProducao(app(ClassificadorSql::class), emTestes: true), $banco);

    expect(fn () => $conexao->insert('insert into a_user (name) values (?)', ['x']))
        ->toThrow(QueryException::class, 'TENTOU CONECTAR');
})->with([
    'sessão única' => 'walle_testes_help',
    'sessão paralela' => 'walle_testes_t07_help',
]);

/*
 * Ordem da trava: o host de produção é conferido ANTES da isenção pelo nome. Mesmo nos testes, com a conexão
 * na lista e o banco de teste do Help Desk (que sozinhos liberariam, como no teste acima), o host de produção barra.
 * O host é fictício (adicionado à lista só aqui) e o PDO é falso: nada sai do contêiner.
 */
test('nos testes, a conexão da lista no banco de teste do Help Desk é barrada se o host for de produção', function (string $banco) {
    config(['walle.help_desk.hosts_producao' => [...config('walle.help_desk.hosts_producao'), 'help.producao.local']]);
    $trava = new TravaHelpDeskProducao(app(ClassificadorSql::class), emTestes: true);
    $conexao = conexaoHelpPeloNome($trava, $banco, 'help.producao.local');

    expect(fn () => $conexao->insert('insert into a_user (name) values (?)', ['x']))->toThrow(
        EscritaNoHelpDeskDeProducao::class,
        'O Help Desk de produção (help.producao.local) é somente consulta: o walle não grava nada lá.',
    );
})->with([
    'sessão única' => 'walle_testes_help',
    'sessão paralela' => 'walle_testes_t07_help',
]);

test('a escrita barrada pelo nome gera uma única auditoria com as chaves exatas, sem SQL nem valores', function () {
    $conexao = conexaoHelpPeloNome();

    expect(fn () => $conexao->update("update a_user set tx_senha = 'segredo-xyz' where name = ?", ['segredo-xyz']))
        ->toThrow(EscritaNoHelpDeskDeProducao::class);

    $auditoria = Auditoria::on('auditoria')->where('acao', 'help_desk.escrita_barrada')->sole();

    expect($auditoria->detalhes)->toEqual([
        'conexao' => CONEXAO_HELP_PELO_NOME,
        'host' => '127.0.0.1',
        'instrucao' => 'update',
        'contexto' => 'console',
    ])
        ->and(json_encode($auditoria->getAttributes()))->not->toContain('segredo-xyz')->not->toContain('tx_senha');
});

test('Usuario->save() e delete() são barrados e cada tentativa fica na auditoria', function () {
    $usuario = Usuario::find(criarUsuarioHelpDesk());

    expect(fn () => $usuario->forceFill(['name' => 'segredo-xyz'])->save())->toThrow(LogicException::class)
        ->and(fn () => $usuario->delete())->toThrow(LogicException::class);

    $instrucoes = Auditoria::on('auditoria')->where('acao', 'help_desk.escrita_barrada')->orderBy('id')->get()
        ->map(fn (Auditoria $auditoria) => $auditoria->detalhes);

    expect($instrucoes->pluck('instrucao')->all())->toBe(['eloquent_save', 'eloquent_delete'])
        ->and($instrucoes->pluck('conexao')->unique()->all())->toBe(['help'])
        ->and(array_keys($instrucoes->first()))->toEqualCanonicalizing(['conexao', 'host', 'instrucao', 'contexto'])
        ->and($instrucoes->toJson())->not->toContain('segredo-xyz');
});

test('a auditoria do save barrado no model somente leitura fica mesmo quando a transação do walle é desfeita', function () {
    $usuario = Usuario::find(criarUsuarioHelpDesk());

    expect(fn () => DB::transaction(fn () => $usuario->forceFill(['name' => 'segredo-xyz'])->save()))
        ->toThrow(LogicException::class);

    $auditoria = Auditoria::on('auditoria')->where('acao', 'help_desk.escrita_barrada')->sole();

    expect($auditoria->detalhes)->toMatchArray(['conexao' => 'help', 'instrucao' => 'eloquent_save'])
        ->and(array_keys($auditoria->detalhes))->toEqualCanonicalizing(['conexao', 'host', 'instrucao', 'contexto'])
        ->and(json_encode($auditoria->getAttributes()))->not->toContain('segredo-xyz');
});

/*
 * Decisão de 25/10/2026: a senha do Help Desk é só a do .env (usuário "walle", só SELECT). A API do Help
 * (192.168.10.251), que entregava a senha do root, não é mais consultada por nada do walle.
 */
test('nada em app/ nem em config/ pede a senha do Help Desk à API do Help', function () {
    $usamApi = collect(File::allFiles([app_path(), config_path()]))
        ->filter(fn (SplFileInfo $arquivo): bool => $arquivo->getExtension() === 'php')
        ->filter(fn (SplFileInfo $arquivo): bool => preg_match('/getPass|HELP_API_|192\.168\.10\.251/', $arquivo->getContents()) === 1)
        ->map(fn (SplFileInfo $arquivo): string => $arquivo->getRelativePathname())
        ->values()
        ->all();

    expect($usamApi)->toBe([]);
});
