<?php

use App\Models\Auditoria;
use App\Models\Concerns\SomenteLeitura;
use App\Support\HelpDesk\ClassificadorSql;
use App\Support\HelpDesk\EscritaNoHelpDeskDeProducao;
use App\Support\HelpDesk\TravaHelpDeskProducao;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * T-0068: a conexão "legado220" lê ao vivo o banco "suporte" do 220 de produção (192.168.10.220), com um
 * usuário que pode gravar. O walle só lê: a conexão é somente leitura pelo nome (fora dos testes) e o host
 * 192.168.10.220 é protegido mesmo que a lista WALLE_HELP_HOSTS_PRODUCAO só traga o .254.
 * SEGURANÇA: nenhum teste daqui abre conexão com o 192.168.10.220. A conexão "de produção" usa porta 1,
 * banco e usuário que não existem e um PDO que só lança exceção ("TENTOU CONECTAR").
 */
const CONEXAO_220_FALSA = 'legado220_producao_teste';

/** Conexão com host de produção do 220, sem rede. O nome padrão é o próprio "legado220". */
function conexao220SemRede(string $nome = 'legado220', string $host = '192.168.10.220', ?TravaHelpDeskProducao $trava = null): Connection
{
    config(['database.connections.'.$nome => array_merge(config('database.connections.mysql'), [
        'host' => $host,
        'port' => 1,
        'database' => 'nao_existe',
        'username' => 'guarda',
        'password' => 'x',
    ])]);
    DB::purge($nome);

    /** @var Connection $conexao */
    $conexao = DB::connection($nome);
    $semRede = fn () => throw new LogicException('TENTOU CONECTAR');
    $conexao->setPdo($semRede)->setReadPdo($semRede);

    $trava?->proteger($conexao);

    return $conexao;
}

beforeEach(function () {
    // Como no .env.example: a lista de hosts de produção só com o Help Desk. O 220 tem de ser protegido mesmo assim.
    config(['walle.help_desk.hosts_producao' => ['192.168.10.254']]);
});

test('nos testes, a legado220 usa o banco de testes da sessão e nunca o 192.168.10.220', function () {
    $legado = DB::connection('legado220');

    expect($legado->getDatabaseName())->toBe(DB::connection('mysql')->getDatabaseName())
        ->and($legado->getDatabaseName())->toMatch('/^walle_testes(_t[0-9]+)?\z/')
        ->and($legado->getConfig('host'))->toBe(DB::connection('mysql')->getConfig('host'))
        ->and($legado->getConfig('host'))->not->toBe('192.168.10.220')
        ->and(TravaHelpDeskProducao::hostDeProducao($legado->getConfig()))->toBeNull()
        ->and(TravaHelpDeskProducao::hostDeProducao((array) config('database.connections.legado220')))->toBeNull();
});

test('nos testes, a legado220 lê as contingências gravadas pelo walle na mesma sessão', function () {
    $venda = criarContingenciaVersatus(7, ['cupom' => '555']);

    expect(DB::connection('legado220')->table('contingencias_versatus_venda')->where('id', $venda)->value('cupom'))->toBe('555')
        ->and(DB::connection('legado220')->table('contingencias_versatus_venda_itens')->where('fk_contingencias_versatus_venda', $venda)->count())->toBe(1);
});

test('nos testes, a legado220 é somente leitura mesmo no banco de testes: a escrita é barrada e auditada', function (Closure $escrever) {
    $venda = criarContingenciaVersatus(7);

    expect(fn () => $escrever(DB::connection('legado220'), $venda))->toThrow(EscritaNoHelpDeskDeProducao::class);

    $auditoria = Auditoria::on('auditoria')->where('acao', TravaHelpDeskProducao::ACAO_AUDITORIA)->sole();

    expect($auditoria->detalhes['conexao'])->toBe('legado220')
        ->and(array_keys($auditoria->detalhes))->toEqualCanonicalizing(['conexao', 'host', 'instrucao', 'contexto'])
        ->and(json_encode($auditoria->getAttributes()))->not->toContain('segredo-xyz')->not->toContain('contingencias_versatus')
        ->and(DB::table('contingencias_versatus_venda')->where('id', $venda)->value('motivo'))->not->toBe('segredo-xyz')
        ->and(DB::table('contingencias_versatus_venda')->count())->toBe(1);
})->with([
    'update' => [fn (Connection $c, int $id) => $c->table('contingencias_versatus_venda')->where('id', $id)->update(['motivo' => 'segredo-xyz'])],
    'insert' => [fn (Connection $c, int $id) => $c->table('contingencias_versatus_venda')->insert(['fk_contingencias_versatus' => 8, 'motivo' => 'segredo-xyz'])],
    'delete' => [fn (Connection $c, int $id) => $c->table('contingencias_versatus_venda')->where('id', $id)->delete()],
    'truncate' => [fn (Connection $c, int $id) => $c->statement('truncate table contingencias_versatus_venda')],
]);

test('com o host 192.168.10.220, a escrita pela legado220 é barrada antes de conectar e auditada sem SQL nem valores', function () {
    $conexao = conexao220SemRede();

    expect(fn () => $conexao->insert("insert into contingencias_versatus_venda (motivo) values ('segredo-xyz')"))->toThrow(
        EscritaNoHelpDeskDeProducao::class,
        'O banco do sistema antigo (220) de produção (192.168.10.220) é somente consulta: o walle não grava nada lá.',
    );

    $auditoria = Auditoria::on('auditoria')->where('acao', TravaHelpDeskProducao::ACAO_AUDITORIA)->sole();

    expect($auditoria->detalhes)->toEqual([
        'conexao' => 'legado220',
        'host' => '192.168.10.220',
        'instrucao' => 'insert',
        'contexto' => 'console',
    ])
        ->and($auditoria->fk_c_cliente)->toBeNull()
        ->and(json_encode($auditoria->getAttributes()))->not->toContain('segredo-xyz')->not->toContain('contingencias_versatus');
});

test('com o host 192.168.10.220, a leitura pela legado220 passa pela trava (só para no conectar falso)', function () {
    $conexao = conexao220SemRede();

    expect(fn () => $conexao->table('contingencias_versatus_venda')->where('fk_contingencias_versatus', 7)->get())
        ->toThrow(QueryException::class, 'TENTOU CONECTAR');
});

test('qualquer conexão com o host 192.168.10.220 é barrada, mesmo fora da lista do config e nos testes', function (string $host) {
    $conexao = conexao220SemRede(CONEXAO_220_FALSA, $host, new TravaHelpDeskProducao(app(ClassificadorSql::class), emTestes: true));

    expect(fn () => $conexao->update('update contingencias_versatus_venda set motivo = ?', ['x']))
        ->toThrow(EscritaNoHelpDeskDeProducao::class, 'é somente consulta');

    expect(Auditoria::on('auditoria')->where('acao', TravaHelpDeskProducao::ACAO_AUDITORIA)->sole()->detalhes)
        ->toMatchArray(['conexao' => CONEXAO_220_FALSA, 'instrucao' => 'update']);
})->with([
    'host exato' => '192.168.10.220',
    'com espaços' => ' 192.168.10.220 ',
]);

test('o Help Desk 192.168.10.254 continua protegido mesmo com a lista de hosts do config vazia', function () {
    config(['walle.help_desk.hosts_producao' => []]);
    $conexao = conexao220SemRede(CONEXAO_220_FALSA, '192.168.10.254');

    expect(fn () => $conexao->delete('delete from a_user'))
        ->toThrow(EscritaNoHelpDeskDeProducao::class, 'O Help Desk de produção (192.168.10.254) é somente consulta');
});

test('fora dos testes, a legado220 só lê com qualquer host, mesmo com a lista de conexões vazia', function (mixed $lista) {
    config(['walle.help_desk.conexoes_somente_leitura' => $lista]);
    $conexao = conexao220SemRede(host: '127.0.0.1', trava: new TravaHelpDeskProducao(app(ClassificadorSql::class), emTestes: false));

    expect(fn () => $conexao->delete('delete from contingencias_versatus_venda'))->toThrow(
        EscritaNoHelpDeskDeProducao::class,
        'O banco do sistema antigo (220) de produção (127.0.0.1) é somente consulta',
    )
        ->and(fn () => $conexao->select('select 1'))->toThrow(QueryException::class, 'TENTOU CONECTAR');
})->with([
    'lista vazia' => [[]],
    'lista sem a legado220' => [['help']],
]);

test('model somente leitura na legado220: o save é barrado e auditado', function () {
    $venda = new class extends Model
    {
        use SomenteLeitura;

        protected $connection = 'legado220';

        protected $table = 'contingencias_versatus_venda';

        public $timestamps = false;
    };

    expect(fn () => $venda->forceFill(['motivo' => 'segredo-xyz'])->save())
        ->toThrow(LogicException::class, 'A tabela contingencias_versatus_venda é do sistema antigo (220) e é somente leitura no walle.');

    $auditoria = Auditoria::on('auditoria')->where('acao', TravaHelpDeskProducao::ACAO_AUDITORIA)->sole();

    expect($auditoria->detalhes)->toMatchArray(['conexao' => 'legado220', 'instrucao' => 'eloquent_save'])
        ->and(json_encode($auditoria->getAttributes()))->not->toContain('segredo-xyz')
        ->and(DB::table('contingencias_versatus_venda')->count())->toBe(0);
});

test('nos testes, apontar a legado220 recusa banco principal em host de produção', function (string $host) {
    $anterior = config('database.connections.legado220');
    config(['database.connections.mysql.host' => $host]);

    expect(fn () => $this->apontarLegado220ParaOBancoDeTeste())
        ->toThrow(RuntimeException::class, 'Nos testes, a conexão legado220 não pode apontar para um host de produção.');

    expect(config('database.connections.legado220'))->toBe($anterior);
})->with([
    '220' => '192.168.10.220',
    'Help Desk' => '192.168.10.254',
]);

test('no config/database.php real, a legado220 é mysql, sem senha padrão e com timeout curto', function () {
    /** @var array{connections: array<string, array<string, mixed>>} $config */
    $config = require config_path('database.php');
    $legado = $config['connections']['legado220'] ?? null;

    expect(is_array($legado))->toBeTrue()
        ->and($legado['driver'])->toBe('mysql')
        ->and($legado['options'][PDO::ATTR_TIMEOUT] ?? null)->toBeLessThanOrEqual(5)
        ->and(file_get_contents(config_path('database.php')))->toContain("env('LEGADO220_DB_SENHA', '')");
});
