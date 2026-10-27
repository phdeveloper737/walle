<?php

use App\Contingencias\Cliente\ConectorDeCliente;
use App\Contingencias\Cliente\FalhaConexaoCliente;
use App\Ferramentas\ExecucaoDeQuery;
use App\Models\Auditoria;
use App\Models\Usuario;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Usuário logado; admin e senha confirmada (agora ou há $minutosAtras minutos) conforme os parâmetros. */
function operadorDeQuery(bool $admin = true, ?int $minutosAtras = 0): Usuario
{
    $usuario = Usuario::find(criarUsuarioHelpDesk());
    test()->actingAs($usuario);
    config(['walle.admins' => $admin ? [$usuario->id] : [], 'walle.confirmar_senha_minutos' => 15]);

    if ($minutosAtras !== null) {
        session()->put('walle.senha_confirmada_em', now()->subMinutes($minutosAtras)->getTimestamp());
    }

    return $usuario;
}

/** Tabela no "servidor do cliente" (o banco de testes); temporária para não encerrar a transação do teste. */
function produtosNoServidorDoCliente(int $quantidade = 2): void
{
    Schema::create('produto_query_teste', function ($table) {
        $table->temporary();
        $table->integer('id');
        $table->string('descricao');
        $table->string('cnpj')->nullable();
    });

    for ($i = 1; $i <= $quantidade; $i++) {
        DB::table('produto_query_teste')->insert(['id' => $i, 'descricao' => "PRODUTO {$i}", 'cnpj' => '12345678000190']);
    }
}

/** @return list<string> SQLs com a marca que chegaram a ser executados */
function sqlsDaQueryExecutados(callable $acao, string $marca): array
{
    $executados = [];
    DB::listen(function ($consulta) use (&$executados, $marca): void {
        if (str_contains($consulta->sql, $marca)) {
            $executados[] = $consulta->sql;
        }
    });
    $acao();

    return $executados;
}

function auditoriaDaQuery(): Auditoria
{
    return Auditoria::query()->where('acao', 'ferramentas.executar_query')->sole();
}

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-27 10:00:00'));
});

test('admin com confirmação válida executa SELECT no cliente e a auditoria guarda o SQL mascarado', function () {
    $usuario = operadorDeQuery();
    $cliente = criarClienteHelpDesk();
    produtosNoServidorDoCliente();
    servidorDoClienteNoBancoDeTestes();

    $resultado = app(ExecucaoDeQuery::class)->executar($cliente, "SELECT id, descricao FROM produto_query_teste WHERE cnpj = '12345678000190' AND id > 0 ORDER BY id;");

    expect($resultado->ok)->toBeTrue()
        ->and($resultado->tipo)->toBe('leitura')
        ->and($resultado->colunas)->toBe(['id', 'descricao'])
        ->and($resultado->linhas)->toBe([['id' => '1', 'descricao' => 'PRODUTO 1'], ['id' => '2', 'descricao' => 'PRODUTO 2']])
        ->and($resultado->truncado)->toBeFalse()
        ->and($resultado->pedeSenha)->toBeFalse();

    $auditoria = auditoriaDaQuery();
    expect($auditoria->usuario_id)->toBe($usuario->id)
        ->and($auditoria->fk_c_cliente)->toBe($cliente)
        ->and($auditoria->detalhes)->toMatchArray([
            'tipo' => 'leitura',
            'resultado' => 'ok',
            'linhas' => 2,
            'sql' => 'SELECT id, descricao FROM produto_query_teste WHERE cnpj = ? AND id > ? ORDER BY id',
        ])
        ->and(json_encode($auditoria->detalhes))->not->toContain('12345678000190');
});

test('admin com confirmação válida executa UPDATE e recebe as linhas afetadas', function () {
    operadorDeQuery();
    $cliente = criarClienteHelpDesk();
    produtosNoServidorDoCliente(3);
    servidorDoClienteNoBancoDeTestes();

    $resultado = app(ExecucaoDeQuery::class)->executar($cliente, "UPDATE produto_query_teste SET descricao = 'NOVO' WHERE id <= 2");

    expect($resultado->ok)->toBeTrue()
        ->and($resultado->tipo)->toBe('escrita')
        ->and($resultado->afetadas)->toBe(2)
        ->and(DB::table('produto_query_teste')->where('descricao', 'NOVO')->count())->toBe(2)
        ->and(auditoriaDaQuery()->detalhes)->toMatchArray([
            'tipo' => 'escrita', 'resultado' => 'ok', 'afetadas' => 2,
            'sql' => 'UPDATE produto_query_teste SET descricao = ? WHERE id <= ?',
        ]);
});

test('sem ser admin ou sem confirmação válida o SQL é recusado antes de conectar no cliente', function (string $sql, bool $admin, ?int $minutosAtras, bool $pedeSenha) {
    operadorDeQuery($admin, $minutosAtras);
    $cliente = criarClienteHelpDesk();
    $this->mock(ConectorDeCliente::class)->shouldNotReceive('conectar');

    $executados = sqlsDaQueryExecutados(
        fn () => expect(app(ExecucaoDeQuery::class)->executar($cliente, $sql))
            ->ok->toBeFalse()
            ->pedeSenha->toBe($pedeSenha)
            ->linhas->toBe([]),
        'marca_recusada',
    );

    expect($executados)->toBe([]);

    $auditoria = auditoriaDaQuery();
    expect($auditoria->fk_c_cliente)->toBe($cliente)
        ->and($auditoria->detalhes)->toMatchArray(['resultado' => 'recusado', 'motivo' => $admin ? 'senha_nao_confirmada' : 'nao_admin'])
        ->and($auditoria->detalhes)->not->toHaveKey('sql')
        ->and(json_encode($auditoria->detalhes))->not->toContain('marca_recusada');
})->with([
    'SELECT sem admin' => ['SELECT 1 AS marca_recusada', false, 0, false],
    'UPDATE sem admin' => ["UPDATE marca_recusada SET a = 'x'", false, 0, false],
    'DELETE sem admin' => ['DELETE FROM marca_recusada', false, 0, false],
    'SELECT sem senha' => ['SELECT 1 AS marca_recusada', true, null, true],
    'UPDATE sem senha' => ["UPDATE marca_recusada SET a = 'x'", true, null, true],
    'DELETE sem senha' => ['DELETE FROM marca_recusada', true, null, true],
    'SELECT com confirmação expirada' => ['SELECT 1 AS marca_recusada', true, 16, true],
    'DELETE com confirmação expirada' => ['DELETE FROM marca_recusada', true, 16, true],
]);

test('visitante sem login não conecta no cliente', function () {
    $cliente = criarClienteHelpDesk();
    $this->mock(ConectorDeCliente::class)->shouldNotReceive('conectar');

    expect(app(ExecucaoDeQuery::class)->executar($cliente, 'SELECT 1'))->ok->toBeFalse();
});

test('confirmação dentro do prazo vale e, ao expirar, pede a senha de novo', function () {
    operadorDeQuery(minutosAtras: 0);
    $cliente = criarClienteHelpDesk();
    servidorDoClienteNoBancoDeTestes();

    expect(app(ExecucaoDeQuery::class)->executar($cliente, 'SELECT 1 AS um'))->ok->toBeTrue();

    $this->travel(16)->minutes();

    expect(app(ExecucaoDeQuery::class)->executar($cliente, 'SELECT 1 AS um'))
        ->ok->toBeFalse()
        ->pedeSenha->toBeTrue();
});

test('mais de uma instrução é recusada sem conectar no cliente', function (string $sql) {
    operadorDeQuery();
    $cliente = criarClienteHelpDesk();
    $this->mock(ConectorDeCliente::class)->shouldNotReceive('conectar');

    expect(app(ExecucaoDeQuery::class)->executar($cliente, $sql))
        ->ok->toBeFalse()
        ->mensagem->toContain('uma instrução');

    expect(auditoriaDaQuery()->detalhes)->toMatchArray(['resultado' => 'recusado', 'motivo' => 'sql_invalido'])
        ->not->toHaveKey('sql');
})->with([
    'duas instruções' => ['SELECT 1; DROP TABLE produto_query_teste'],
    'segunda depois de comentário' => ["SELECT 1 -- x\n; DELETE FROM produto_query_teste"],
    'comentário executável' => ['SELECT 1 /*! ; DROP TABLE produto_query_teste */'],
    'texto sem fim' => ["SELECT 'abc"],
    'vazio' => ['  ;  '],
]);

test('ponto e vírgula dentro de texto ou no fim não conta como outra instrução', function () {
    operadorDeQuery();
    $cliente = criarClienteHelpDesk();
    servidorDoClienteNoBancoDeTestes();

    expect(app(ExecucaoDeQuery::class)->executar($cliente, "SELECT ';' AS sinal;  "))
        ->ok->toBeTrue()
        ->linhas->toBe([['sinal' => ';']]);
});

test('resultado grande é cortado no limite de linhas e sinalizado', function () {
    operadorDeQuery();
    config(['walle.ferramentas.query_limite_linhas' => 3]);
    $cliente = criarClienteHelpDesk();
    produtosNoServidorDoCliente(5);
    servidorDoClienteNoBancoDeTestes();

    $resultado = app(ExecucaoDeQuery::class)->executar($cliente, 'SELECT id FROM produto_query_teste ORDER BY id');

    expect($resultado->ok)->toBeTrue()
        ->and($resultado->linhas)->toBe([['id' => '1'], ['id' => '2'], ['id' => '3']])
        ->and($resultado->truncado)->toBeTrue()
        ->and($resultado->mensagem)->toContain('3');
    expect(auditoriaDaQuery()->detalhes)->toMatchArray(['linhas' => 3, 'truncado' => true]);
});

test('falha de conexão mostra o motivo sem host, usuário, senha nem o SQL', function () {
    operadorDeQuery();
    $cliente = criarClienteHelpDesk(servidor: [
        'servidor_docker' => '10.77.66.55', 'usuario_mysql_docker' => 'usuario_secreto', 'senha_mysql_docker' => 'senha-secreta-cliente',
    ]);
    $this->mock(ConectorDeCliente::class)->shouldReceive('conectar')->once()
        ->andThrow(FalhaConexaoCliente::acessoNegado("cliente {$cliente} em 10.77.66.55:3306 usuario_secreto senha-secreta-cliente"));

    $resultado = app(ExecucaoDeQuery::class)->executar($cliente, "SELECT 'valor-sensivel' AS marca");
    $auditoria = json_encode(auditoriaDaQuery()->detalhes);

    expect($resultado->ok)->toBeFalse()
        ->and($resultado->mensagem)->toBe(FalhaConexaoCliente::acessoNegado('')->getMessage());

    foreach (['10.77.66.55', 'usuario_secreto', 'senha-secreta-cliente', 'valor-sensivel'] as $segredo) {
        expect($resultado->mensagem)->not->toContain($segredo)
            ->and($auditoria)->not->toContain($segredo);
    }

    expect(auditoriaDaQuery()->detalhes)->toMatchArray(['resultado' => 'erro']);
});

test('erro do MySQL do cliente volta sem dados da conexão nem o SQL', function () {
    operadorDeQuery();
    $cliente = criarClienteHelpDesk();
    servidorDoClienteNoBancoDeTestes();

    $resultado = app(ExecucaoDeQuery::class)->executar($cliente, "SELECT * FROM tabela_inexistente_query WHERE senha = 'valor-sensivel'");

    expect($resultado->ok)->toBeFalse()
        ->and($resultado->mensagem)->toContain('tabela_inexistente_query')
        ->not->toContain('Host:')
        ->not->toContain('Connection:')
        ->not->toContain('valor-sensivel');
    expect(auditoriaDaQuery()->detalhes)->toMatchArray(['resultado' => 'erro', 'sql' => 'SELECT * FROM tabela_inexistente_query WHERE senha = ?']);
});

test('cliente sem cadastro do servidor no Help Desk não conecta', function () {
    operadorDeQuery();
    $this->mock(ConectorDeCliente::class)->shouldNotReceive('conectar');

    expect(app(ExecucaoDeQuery::class)->executar(999999, 'SELECT 1'))
        ->ok->toBeFalse()
        ->mensagem->toBe(FalhaConexaoCliente::semCadastroNoHelpDesk()->getMessage());
});
