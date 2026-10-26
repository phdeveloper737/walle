<?php

use App\Models\Auditoria;
use App\Support\HelpDesk\ClassificadorSql;
use App\Support\HelpDesk\EscritaNoHelpDeskDeProducao;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/*
 * SEGURANÇA: nenhum teste daqui abre conexão com o 192.168.10.254, nem para ler.
 * A conexão "de produção" é de mentira: host da lista, porta 1, banco e usuário que não existem.
 * Além disso o PDO dela é trocado por um que só lança exceção: se a trava deixasse passar,
 * o teste veria "TENTOU CONECTAR" (nada sai do contêiner).
 */
const CONEXAO_PRODUCAO_FALSA = 'help_producao_teste';

function conexaoDeProducaoFalsa(string $host = '192.168.10.254'): Connection
{
    config(['database.connections.'.CONEXAO_PRODUCAO_FALSA => array_merge(config('database.connections.mysql'), [
        'host' => $host,
        'port' => 1,
        'database' => 'nao_existe',
        'username' => 'guarda',
        'password' => 'x',
    ])]);

    /** @var Connection $conexao */
    $conexao = DB::connection(CONEXAO_PRODUCAO_FALSA);
    $semRede = fn () => throw new LogicException('TENTOU CONECTAR');
    $conexao->setPdo($semRede)->setReadPdo($semRede);

    return $conexao;
}

test('na conexão de produção, toda escrita é barrada pela trava antes de conectar', function (Closure $escrever) {
    $conexao = conexaoDeProducaoFalsa();

    expect(fn () => $escrever($conexao))->toThrow(
        EscritaNoHelpDeskDeProducao::class,
        'O Help Desk de produção (192.168.10.254) é somente consulta: o walle não grava nada lá.',
    );
})->with([
    'query builder update' => [fn (Connection $c) => $c->table('a_user')->where('id', 1)->update(['tx_senha' => 'x'])],
    'query builder insert' => [fn (Connection $c) => $c->table('a_user')->insert(['name' => 'x'])],
    'query builder delete' => [fn (Connection $c) => $c->table('a_user')->where('id', 1)->delete()],
    'query builder truncate' => [fn (Connection $c) => $c->table('a_user')->truncate()],
    'DB::update' => [fn (Connection $c) => $c->update('update a_user set tx_senha = ? where id = ?', ['x', 1])],
    'DB::insert' => [fn (Connection $c) => $c->insert('insert into a_user (name) values (?)', ['x'])],
    'DB::delete' => [fn (Connection $c) => $c->delete('delete from a_user where id = ?', [1])],
    'statement TRUNCATE' => [fn (Connection $c) => $c->statement('TRUNCATE TABLE a_user')],
    'affectingStatement REPLACE' => [fn (Connection $c) => $c->affectingStatement('replace into a_user (id, name) values (1, ?)', ['x'])],
    'unprepared' => [fn (Connection $c) => $c->unprepared('DROP TABLE a_user')],
    'unprepared com select na frente' => [fn (Connection $c) => $c->unprepared('select 1; delete from a_user')],
    'schema (DDL)' => [fn (Connection $c) => $c->getSchemaBuilder()->create('tabela_nova', fn (Blueprint $t) => $t->id())],
    'cursor' => [fn (Connection $c) => iterator_to_array($c->cursor('delete from a_user'))],
    'lazy com lock' => [fn (Connection $c) => $c->table('a_user')->orderBy('id')->lockForUpdate()->lazy()->all()],
    'select for update' => [fn (Connection $c) => $c->select('select * from a_user where id = 1 for update')],
    'Eloquent save' => [function () {
        $usuario = new class extends Model
        {
            protected $connection = CONEXAO_PRODUCAO_FALSA;

            protected $table = 'a_user';

            public $timestamps = false;
        };
        $usuario->forceFill(['name' => 'Intruso', 'user' => 'intruso'])->save();
    }],
]);

test('na conexão de produção, a leitura passa pela trava (só para no conectar falso)', function () {
    $conexao = conexaoDeProducaoFalsa();

    expect(fn () => $conexao->table('a_user')->where('user', 'maria')->first())
        ->toThrow(QueryException::class, 'TENTOU CONECTAR');
    expect(fn () => $conexao->select('show tables'))
        ->toThrow(QueryException::class, 'TENTOU CONECTAR');
});

test('a trava vale para a conexão recriada depois de um DB::purge e para conexões dinâmicas', function () {
    conexaoDeProducaoFalsa();
    DB::purge(CONEXAO_PRODUCAO_FALSA);
    $recriada = conexaoDeProducaoFalsa();

    expect(fn () => $recriada->update('update a_user set tx_senha = ?', ['x']))
        ->toThrow(EscritaNoHelpDeskDeProducao::class);

    $dinamica = DB::build(config('database.connections.'.CONEXAO_PRODUCAO_FALSA));
    $semRede = fn () => throw new LogicException('TENTOU CONECTAR');
    $dinamica->setPdo($semRede)->setReadPdo($semRede);

    expect(fn () => $dinamica->delete('delete from a_user'))
        ->toThrow(EscritaNoHelpDeskDeProducao::class);
});

test('o classificador reconhece as leituras', function (string $sql) {
    expect(app(ClassificadorSql::class)->ehLeitura($sql))->toBeTrue();
})->with([
    'select' => 'select * from `a_user` where `user` = ? limit 1',
    'SELECT maiúsculo com espaços' => "   \n\t SELECT id FROM a_user",
    'comentário de bloco' => '/* login */ select 1',
    'comentário de linha' => "-- quem entrou\nselect 1",
    'comentário com #' => "# x\nSeLeCt 1",
    'parênteses (union)' => '(select id from a) union (select id from b)',
    'show' => 'show tables',
    'SHOW COLUMNS' => 'SHOW FULL COLUMNS FROM a_user',
    'describe' => 'describe a_user',
    'desc' => 'DESC a_user',
    'explain' => 'explain select * from a_user',
    'explain com comentário' => '/* plano */ EXPLAIN SELECT 1',
    'texto parecido dentro de literal' => "select 'for update; delete from x' as frase",
    'ponto e vírgula no fim' => 'select 1;',
]);

test('o classificador barra o que não é leitura', function (string $sql) {
    expect(app(ClassificadorSql::class)->ehLeitura($sql))->toBeFalse();
})->with([
    'update' => 'update a_user set tx_senha = ? where id = ?',
    'insert' => 'INSERT INTO s_atendimento (problema) VALUES (?)',
    'delete' => 'delete from a_user',
    'replace' => 'replace into a_user (id) values (1)',
    'truncate' => 'truncate table a_user',
    'ddl' => 'alter table a_user add x int',
    'comentário na frente' => '/* select */ update a_user set x = 1',
    'comentário de linha na frente' => "-- select\ndelete from a_user",
    'parênteses na frente' => '((update a_user set x = 1))',
    'select for update' => 'select * from a_user where id = 1 for update',
    'select FOR   UPDATE com quebra' => "select * from a_user\nFOR\n UPDATE",
    'lock in share mode' => 'select * from a_user lock in share mode',
    'for share' => 'select * from a_user for share',
    'into outfile' => "select * from a_user into outfile '/tmp/x'",
    'into dumpfile' => "SELECT tx_senha FROM a_user INTO DUMPFILE '/tmp/x'",
    'duas instruções' => 'select 1; delete from a_user',
    'duas instruções com -- sem espaço' => 'select 1 --1; delete from a_user',
    'comentário executável' => 'select * from a_user /*!50000 for update */',
    'explain analyze' => 'explain analyze select 1',
    'explain de update' => 'explain update a_user set x = 1',
    'set' => 'set names utf8',
    // D14: nem a transação "só leitura" é liberada (a ferramenta database-query do Boost segue barrada)
    'set transaction read only' => 'SET TRANSACTION READ ONLY',
    'call' => 'call procedimento()',
    'create' => 'create table x (id int)',
    'drop' => 'drop table a_user',
    'rename' => 'rename table a_user to b_user',
    'grant' => "grant all on *.* to 'x'@'%'",
    'load data' => "load data infile '/tmp/x' into table a_user",
    'lock tables' => 'lock tables a_user write',
    'handler' => 'handler a_user open',
    'do' => 'do sleep(1)',
    'vazio' => '   ',
    'só comentário' => '/* nada */',
]);

test('a escrita barrada na conexão de produção gera uma única auditoria, sem SQL nem valores', function () {
    $conexao = conexaoDeProducaoFalsa();

    expect(fn () => $conexao->insert("insert into a_user (name, tx_senha) values ('segredo-xyz', ?)", ['segredo-xyz']))
        ->toThrow(EscritaNoHelpDeskDeProducao::class);

    $auditoria = Auditoria::where('acao', 'help_desk.escrita_barrada')->sole();

    expect($auditoria->detalhes)->toEqual([
        'conexao' => CONEXAO_PRODUCAO_FALSA,
        'host' => '192.168.10.254',
        'instrucao' => 'insert',
        'contexto' => 'console',
    ])
        ->and($auditoria->fk_c_cliente)->toBeNull()
        ->and(json_encode($auditoria->getAttributes()))->not->toContain('segredo-xyz')->not->toContain('a_user');
});

test('se a própria auditoria é barrada, não entra em laço: a escrita é barrada e fica um aviso no log', function () {
    // O host do banco do walle também na lista: a gravação da auditoria é barrada pela mesma trava
    config(['walle.help_desk.hosts_producao' => ['192.168.10.254', config('database.connections.mysql.host')]]);
    $conexao = conexaoDeProducaoFalsa();
    Log::spy();

    expect(fn () => $conexao->delete('delete from a_user where tx_senha = ?', ['segredo-xyz']))
        ->toThrow(EscritaNoHelpDeskDeProducao::class, '(192.168.10.254)');

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $mensagem, array $contexto): bool => $contexto === [
        'conexao' => CONEXAO_PRODUCAO_FALSA,
        'host' => '192.168.10.254',
        'instrucao' => 'delete',
        'contexto' => 'console',
    ]);
    expect(Auditoria::count())->toBe(0);
});

test('o host é comparado sem maiúsculas nem espaços e a lista vem do config', function () {
    config(['walle.help_desk.hosts_producao' => ['192.168.10.254', 'help.producao.local']]);
    $conexao = conexaoDeProducaoFalsa(' HELP.Producao.LOCAL ');

    expect(fn () => $conexao->insert('insert into a_user (name) values (?)', ['x']))
        ->toThrow(EscritaNoHelpDeskDeProducao::class, 'O Help Desk de produção (HELP.Producao.LOCAL) é somente consulta');
});

test('host fora da lista segue gravando normalmente', function () {
    $id = DB::connection('help')->table('a_user')->insertGetId(['name' => 'Teste', 'user' => 'teste', 'tx_senha' => 'x', 'deletado' => 'N']);
    DB::connection('help')->table('a_user')->where('id', $id)->update(['name' => 'Teste 2']);

    $this->assertDatabaseHas('a_user', ['id' => $id, 'name' => 'Teste 2'], 'help');
});
