<?php

use App\Ferramentas\RoboTarefas;
use App\Livewire\Ferramentas;
use App\Models\Auditoria;
use App\Models\TaskSchedulerQuery;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

function entrarComoAdminFerramentas(bool $admin = true): Usuario
{
    $usuario = Usuario::find(criarUsuarioHelpDesk());
    test()->actingAs($usuario);
    config(['walle.admins' => $admin ? [$usuario->id] : []]);

    return $usuario;
}

function queryCadastradaFerramentas(string $sql): int
{
    return (int) DB::table('task_scheduler_querys')->insertGetId([
        'descricao' => 'Tarefa de teste', 'query_execute' => base64_encode($sql), 'ativo' => 'S', 'deletado' => 'N',
    ]);
}

function confirmarSenhaFerramentas(): void
{
    session()->put('walle.senha_confirmada_em', now()->getTimestamp());
}

test('admin cria a query depois de confirmar a senha, nasce desativada e a auditoria não leva o SQL', function () {
    entrarComoAdminFerramentas();

    Livewire::test(Ferramentas::class)
        ->set('descricao', 'Ajuste de teste')
        ->set('sql', 'SELECT 1 AS marca_secreta')
        ->call('salvar')
        ->assertSet('pedindoSenha', true)
        ->set('senhaConfirmacao', 'segredo')
        ->call('confirmarSenha')
        ->assertSee('Ajuste de teste')
        ->assertDontSee('marca_secreta');

    $query = TaskSchedulerQuery::query()->sole();
    expect($query->ativo)->toBe('N')
        ->and($query->query_execute)->toBe(base64_encode('SELECT 1 AS marca_secreta'));

    $auditoria = Auditoria::query()->where('acao', 'ferramentas.salvar_query')->sole();
    expect(json_encode($auditoria->detalhes))->not->toContain('marca_secreta');
});

test('quem não é admin recebe 403 ao salvar, editar e alternar', function () {
    entrarComoAdminFerramentas(false);
    $id = queryCadastradaFerramentas('SELECT 1');

    Livewire::test(Ferramentas::class)->set('descricao', 'x')->set('sql', 'SELECT 1')->call('salvar')->assertForbidden();
    Livewire::test(Ferramentas::class)->call('editar', $id)->assertForbidden();
    Livewire::test(Ferramentas::class)->call('alternarAtivo', $id)->assertForbidden();
    expect(TaskSchedulerQuery::query()->count())->toBe(1);
});

test('não admin com dados inválidos também recebe 403, antes da validação', function () {
    entrarComoAdminFerramentas(false);

    Livewire::test(Ferramentas::class)->set('descricao', '')->set('sql', '')->call('salvar')->assertForbidden();
});

test('sem a senha confirmada nada é salvo e editar também pede a senha', function () {
    entrarComoAdminFerramentas();
    $id = queryCadastradaFerramentas('SELECT 1');

    Livewire::test(Ferramentas::class)->set('descricao', 'Nova')->set('sql', 'SELECT 2')->call('salvar')->assertSet('pedindoSenha', true);
    Livewire::test(Ferramentas::class)->call('editar', $id)->assertSet('pedindoSenha', true)->assertSet('sql', '');
    Livewire::test(Ferramentas::class)->call('alternarAtivo', $id)->assertSet('pedindoSenha', true);

    expect(TaskSchedulerQuery::query()->count())->toBe(1)
        ->and(TaskSchedulerQuery::query()->sole()->ativo)->toBe('S');
});

test('salvar e editar preservam a quebra de linha da query', function () {
    entrarComoAdminFerramentas();
    confirmarSenhaFerramentas();
    $sql = "SELECT 1\nFROM dual";

    $tela = Livewire::test(Ferramentas::class)->set('descricao', 'Com quebra')->set('sql', $sql)->call('salvar');
    $query = TaskSchedulerQuery::query()->sole();
    expect(base64_decode($query->query_execute))->toBe($sql);

    $tela->call('editar', $query->id)->assertSet('sql', $sql)->assertSet('editandoId', $query->id);
    expect(Auditoria::query()->where('acao', 'ferramentas.editar_query')->count())->toBe(1);
});

test('salvar atualiza a query existente sem criar outra', function () {
    entrarComoAdminFerramentas();
    confirmarSenhaFerramentas();
    $id = queryCadastradaFerramentas('SELECT 1');

    Livewire::test(Ferramentas::class)->call('editar', $id)
        ->set('descricao', 'Renomeada')->set('sql', 'SELECT 2')->call('salvar')
        ->assertSet('editandoId', null);

    $query = TaskSchedulerQuery::query()->sole();
    expect($query->descricao)->toBe('Renomeada')
        ->and($query->query_execute)->toBe(base64_encode('SELECT 2'))
        ->and($query->ativo)->toBe('S');
});

test('o limite vale para o texto codificado', function () {
    entrarComoAdminFerramentas();
    confirmarSenhaFerramentas();

    Livewire::test(Ferramentas::class)->set('descricao', 'Grande')->set('sql', str_repeat('a', Ferramentas::LIMITE_SQL + 1))
        ->call('salvar')->assertHasErrors('sql');
    expect(TaskSchedulerQuery::query()->count())->toBe(0);

    Livewire::test(Ferramentas::class)->set('descricao', 'No limite')->set('sql', str_repeat('a', Ferramentas::LIMITE_SQL))
        ->call('salvar')->assertHasNoErrors();
    expect(strlen((string) TaskSchedulerQuery::query()->sole()->query_execute))->toBeLessThanOrEqual(3737);
});

test('alternar muda ativo e registra só o id', function () {
    entrarComoAdminFerramentas();
    confirmarSenhaFerramentas();
    $id = queryCadastradaFerramentas('SELECT 1');

    Livewire::test(Ferramentas::class)->call('alternarAtivo', $id);

    expect(TaskSchedulerQuery::query()->find($id)->ativo)->toBe('N');
    $auditoria = Auditoria::query()->where('acao', 'ferramentas.alternar_query')->sole();
    expect($auditoria->detalhes)->toMatchArray(['id' => $id, 'ativo' => false]);
});

test('o relatório decodifica o base64 e mantém o texto FALHA', function () {
    entrarComoAdminFerramentas();
    $cliente = criarClienteHelpDesk(['razao_social' => 'MERCADO DO ZE']);
    $tarefa = queryCadastradaFerramentas('SELECT 1');
    DB::table('task_scheduler_querys_log')->insert([
        ['fk_task_scheduler_querys' => $tarefa, 'fk_c_cliente' => $cliente, 'log_query' => base64_encode('Erro: tabela sumiu')],
        ['fk_task_scheduler_querys' => $tarefa, 'fk_c_cliente' => $cliente, 'log_query' => 'FALHA AO CONECTAR NO CLIENTE: sem resposta'],
    ]);

    Livewire::withQueryParams(['aba' => 'relatorio'])->test(Ferramentas::class)
        ->assertSee('Erro: tabela sumiu')
        ->assertSee('FALHA AO CONECTAR NO CLIENTE: sem resposta')
        ->assertSee('MERCADO DO ZE');
});

test('relatório sem execuções mostra o estado vazio', function () {
    entrarComoAdminFerramentas();

    Livewire::withQueryParams(['aba' => 'relatorio'])->test(Ferramentas::class)->assertSee('Nenhuma execução ainda');
});

test('aba inválida volta para querys', function () {
    entrarComoAdminFerramentas();

    Livewire::withQueryParams(['aba' => 'xyz'])->test(Ferramentas::class)->assertSet('aba', 'querys');
});

test('avisa quando o robô está desligado', function () {
    entrarComoAdminFerramentas();
    config(['walle.tarefas.ativo' => false]);
    Livewire::test(Ferramentas::class)->assertSee('robô de tarefas está desligado');

    config(['walle.tarefas.ativo' => true]);
    Livewire::test(Ferramentas::class)->assertDontSee('robô de tarefas está desligado');
});

test('ida e volta: o robô executa a query criada pela tela', function () {
    entrarComoAdminFerramentas();
    config(['walle.tarefas.ativo' => true]);
    $this->travelTo(now()->setTime(10, 0));
    confirmarSenhaFerramentas();
    servidorDoClienteNoBancoDeTestes();
    $cliente = criarClienteHelpDesk();

    Livewire::test(Ferramentas::class)->set('descricao', 'Ida e volta')->set('sql', "SELECT 1\nAS marca_ida_volta")->call('salvar');
    $query = TaskSchedulerQuery::query()->sole();
    $query->update(['ativo' => 'S']);

    $executados = [];
    DB::listen(function ($consulta) use (&$executados): void {
        if (str_contains($consulta->sql, 'marca_ida_volta')) {
            $executados[] = $consulta->sql;
        }
    });
    app(RoboTarefas::class)->rodarPendentes();

    expect($executados)->toHaveCount(1)
        ->and(DB::table('task_scheduler_querys_log')->where('fk_task_scheduler_querys', $query->id)->where('fk_c_cliente', $cliente)->pluck('log_query')->all())->toBe(['T0s=']);
});
