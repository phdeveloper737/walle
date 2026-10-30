<?php

use App\Contingencias\AcoesContingencias;
use App\Contingencias\Cliente\ConectorDeCliente;
use App\Contingencias\Cliente\ConexaoComCliente;
use App\Ferramentas\ExecucaoDeQuery;
use App\Ferramentas\RoboTarefas;
use App\Models\Usuario;
use App\Sactus\Ssh\ResultadoComando;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-30 10:00:00'));
});

test('query agendada real do 220 chega inteira ao conector falso e grava OK', function () {
    $amostra = json_decode(file_get_contents(base_path('tests/Fixtures/legado/acoes/query-agendada.json')), true, flags: JSON_THROW_ON_ERROR);
    config(['walle.tarefas.ativo' => true]);
    $cliente = criarClienteHelpDesk();
    $tarefa = DB::table('task_scheduler_querys')->insertGetId([
        'descricao' => $amostra['descricao'],
        'query_execute' => $amostra['query_base64'],
        'ativo' => 'S',
        'deletado' => 'N',
    ]);
    $recebidos = [];
    $conexao = Mockery::mock(Connection::class);
    $conexao->shouldReceive('statement')->once()->andReturnUsing(function (string $sql) use (&$recebidos): bool {
        $recebidos[] = $sql;

        return true;
    });
    $this->mock(ConectorDeCliente::class)->shouldReceive('conectar')->once()
        ->andReturn(new ConexaoComCliente($conexao, 'actus', fecharAoTerminar: false));

    app(RoboTarefas::class)->rodarPendentes(clienteId: $cliente);
    app(RoboTarefas::class)->rodarPendentes(clienteId: $cliente);

    expect($recebidos)->toBe([$amostra['sql_enviado']], 'SQL esperado (220) versus recebido (walle)');
    expect(DB::table('task_scheduler_querys_log')->where('fk_task_scheduler_querys', $tarefa)->pluck('log_query')->all())
        ->toBe(['T0s='], '220: log OK em base64; walle: log persistido, sem reenvio');
});

test('reenvio entrega o XML 858 do contrato do 220 ao SSH falso', function () {
    $amostra = json_decode(file_get_contents(base_path('tests/Fixtures/legado/acoes/reenviar-pdv.json')), true, flags: JSON_THROW_ON_ERROR);
    config(['walle.contingencias.ativo' => true]);
    $cliente = criarClienteHelpDesk([], [
        'pasta_multiempresa' => 'S',
        'servidor_docker' => '192.0.2.10',
        'porta_ssh_docker' => '2222',
        'usuario_ssh_docker' => 'root',
        'senha_ssh_docker' => '[MASCARADO]',
    ]);
    $ssh = sshFalso()->servidor('192.0.2.10', 2222, '[MASCARADO]')
        ->regra('192.0.2.10', 2222, fn (string $comando) => new ResultadoComando(0));
    criarContingenciaSactus(['cupom' => '500', 'pdv' => '2'], chave: $amostra['chave_acesso']);
    servidorDoClienteNoBancoDeTestes();

    $resultado = app(AcoesContingencias::class)->reenviarAoPdv($cliente, [['loja' => 1, 'pdv' => 2, 'cupom' => 500]]);

    expect($resultado->enviados)->toBe(1);
    $comandos = $ssh->comandosEm('192.0.2.10', 2222);
    expect($comandos)->toHaveCount(1);
    preg_match('/<\?xml.*?<\/comando>/', $comandos[0], $xml);
    expect($xml[0] ?? null)->toBe($amostra['xml'], 'XML esperado (220) versus recebido (walle); transporte SSH alterado conforme 8.1');
});

test('SQL manual recusado não abre conexão com cliente', function (bool $admin, bool $senha, string $sql) {
    $usuario = Usuario::findOrFail(criarUsuarioHelpDesk());
    $this->actingAs($usuario);
    config(['walle.admins' => $admin ? [$usuario->id] : []]);
    session()->forget('walle.senha_confirmada_em');
    if ($senha) {
        session()->put('walle.senha_confirmada_em', now()->getTimestamp());
    }
    $this->mock(ConectorDeCliente::class)->shouldNotReceive('conectar');

    $resultado = app(ExecucaoDeQuery::class)->executar(1, $sql);

    expect($resultado->ok)->toBeFalse();
})->with([
    'SELECT sem senha' => [true, false, 'SELECT id FROM a_user'],
    'UPDATE sem senha' => [true, false, 'UPDATE produto SET ativo = 1'],
    'DELETE sem senha' => [true, false, 'DELETE FROM produto'],
    'SELECT sem admin' => [false, true, 'SELECT id FROM a_user'],
    'UPDATE sem admin' => [false, true, 'UPDATE produto SET ativo = 1'],
    'DELETE sem admin' => [false, true, 'DELETE FROM produto'],
]);
