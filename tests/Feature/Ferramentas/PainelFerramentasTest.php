<?php

use App\Ferramentas\RoboTarefas;
use App\Livewire\Ferramentas;
use App\Models\Auditoria;
use App\Models\TaskSchedulerQuery;
use App\Models\TaskSchedulerQueryLog;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

function entrarParaFerramentas(bool $admin): Usuario
{
    $usuario = Usuario::find(criarUsuarioHelpDesk());
    test()->actingAs($usuario);
    config(['walle.admins' => $admin ? [$usuario->id] : []]);

    return $usuario;
}

test('admin cria uma query depois de confirmar a senha e ela aparece na lista com auditoria', function () {
    entrarParaFerramentas(true);

    Livewire::test(Ferramentas::class)
        ->set('descricao', 'Limpar tabela de teste')
        ->set('queryExecute', 'SELECT 1 /* senha=abc123 */')
        ->call('salvar')
        ->assertSet('pedindoSenha', true)
        ->set('senhaConfirmacao', 'segredo')
        ->call('confirmarSenha')
        ->assertSet('pedindoSenha', false)
        ->assertSee('Limpar tabela de teste');

    $query = TaskSchedulerQuery::firstOrFail();
    expect($query->descricao)->toBe('Limpar tabela de teste')->and($query->ativo)->toBe('N');

    $auditoria = Auditoria::where('acao', 'ferramentas.salvar_query')->firstOrFail();
    expect(json_encode($auditoria->detalhes))->not->toContain('abc123')->not->toContain('SELECT');
});

test('usuario que nao e admin recebe 403 ao salvar', function () {
    entrarParaFerramentas(false);

    Livewire::test(Ferramentas::class)
        ->set('descricao', 'x')
        ->set('queryExecute', 'SELECT 1')
        ->call('salvar')
        ->assertForbidden();

    expect(TaskSchedulerQuery::count())->toBe(0);
});

test('sem senha confirmada nada e salvo', function () {
    entrarParaFerramentas(true);

    Livewire::test(Ferramentas::class)
        ->set('descricao', 'x')
        ->set('queryExecute', 'SELECT 1')
        ->call('salvar')
        ->assertSet('pedindoSenha', true);

    expect(TaskSchedulerQuery::count())->toBe(0);
});

test('admin ativa e desativa uma query com a senha confirmada', function () {
    entrarParaFerramentas(true);
    session()->put('walle.senha_confirmada_em', now()->getTimestamp());
    $query = TaskSchedulerQuery::create(['descricao' => 'Rotina', 'query_execute' => 'SELECT 1', 'ativo' => 'N', 'data_registro' => now()]);

    Livewire::test(Ferramentas::class)->call('alternarAtivo', $query->id);

    expect($query->fresh()->ativo)->toBe('S')
        ->and(Auditoria::where('acao', 'ferramentas.alternar_query')->count())->toBe(1);
});

test('formulario invalido nao salva', function () {
    entrarParaFerramentas(true);
    session()->put('walle.senha_confirmada_em', now()->getTimestamp());

    Livewire::test(Ferramentas::class)->set('descricao', '')->call('salvar')->assertHasErrors(['descricao', 'queryExecute']);

    expect(TaskSchedulerQuery::count())->toBe(0);
});

test('a aba relatorio mostra os logs e o vazio, e aba invalida volta para querys', function () {
    entrarParaFerramentas(false);

    Livewire::withQueryParams(['aba' => 'relatorio'])->test(Ferramentas::class)->assertOk()->assertSee('Nenhuma execução registrada');
    Livewire::withQueryParams(['aba' => 'xyz'])->test(Ferramentas::class)->assertSet('aba', 'querys');

    $cliente = criarClienteHelpDesk();
    $query = TaskSchedulerQuery::create(['descricao' => 'Rotina', 'query_execute' => 'SELECT 1', 'ativo' => 'S', 'data_registro' => now()]);
    TaskSchedulerQueryLog::create(['fk_task_scheduler_querys' => $query->id, 'fk_c_cliente' => $cliente, 'log_query' => 'Erro: tabela inexistente', 'data_time' => now()]);

    Livewire::withQueryParams(['aba' => 'relatorio'])->test(Ferramentas::class)->assertSee('Erro: tabela inexistente')->assertSee('Rotina');
});

test('a query e gravada em base64 como no 220 e o formulario de edicao mostra o SQL decodificado', function () {
    entrarParaFerramentas(true);
    session()->put('walle.senha_confirmada_em', now()->getTimestamp());

    Livewire::test(Ferramentas::class)
        ->set('descricao', 'Rotina')
        ->set('queryExecute', '  SELECT 1  ')
        ->call('salvar');

    $query = TaskSchedulerQuery::firstOrFail();
    expect($query->query_execute)->toBe(base64_encode('SELECT 1'));

    Livewire::test(Ferramentas::class)->call('editar', $query->id)->assertSet('queryExecute', 'SELECT 1');
});

test('editar pede a senha e nao carrega o SQL antes dela', function () {
    entrarParaFerramentas(true);
    $query = TaskSchedulerQuery::create(['descricao' => 'Rotina', 'query_execute' => base64_encode('SELECT 1'), 'data_registro' => now()]);

    Livewire::test(Ferramentas::class)->call('editar', $query->id)
        ->assertSet('pedindoSenha', true)->assertSet('queryExecute', '');
});

test('usuario que nao e admin recebe 403 ao editar e ao alternar', function () {
    entrarParaFerramentas(false);
    $query = TaskSchedulerQuery::create(['descricao' => 'Rotina', 'query_execute' => base64_encode('SELECT 1'), 'data_registro' => now()]);

    Livewire::test(Ferramentas::class)->call('editar', $query->id)->assertForbidden();
    Livewire::test(Ferramentas::class)->call('alternarAtivo', $query->id)->assertForbidden();

    expect($query->fresh()->ativo)->toBe('N');
});

test('admin atualiza uma query existente sem criar outra', function () {
    entrarParaFerramentas(true);
    session()->put('walle.senha_confirmada_em', now()->getTimestamp());
    $query = TaskSchedulerQuery::create(['descricao' => 'Antiga', 'query_execute' => base64_encode('SELECT 1'), 'data_registro' => now()]);

    Livewire::test(Ferramentas::class)
        ->call('editar', $query->id)
        ->set('descricao', 'Nova')
        ->set('queryExecute', 'SELECT 2')
        ->call('salvar')
        ->assertSet('editandoId', null);

    expect(TaskSchedulerQuery::count())->toBe(1)
        ->and($query->fresh()->descricao)->toBe('Nova')
        ->and($query->fresh()->query_execute)->toBe(base64_encode('SELECT 2'))
        ->and(Auditoria::where('acao', 'ferramentas.editar_query')->count())->toBe(1);
});

test('o limite de tamanho vale para o texto ja em base64', function () {
    entrarParaFerramentas(true);
    session()->put('walle.senha_confirmada_em', now()->getTimestamp());

    Livewire::test(Ferramentas::class)->set('descricao', 'x')->set('queryExecute', str_repeat('a', 2803))
        ->call('salvar')->assertHasErrors(['queryExecute']);
    Livewire::test(Ferramentas::class)->set('descricao', 'x')->set('queryExecute', str_repeat('a', 2802))
        ->call('salvar')->assertHasNoErrors();

    expect(TaskSchedulerQuery::count())->toBe(1);
});

test('o relatorio decodifica o resultado em base64 e mostra a falha de conexao como esta', function () {
    entrarParaFerramentas(false);
    $cliente = criarClienteHelpDesk();
    $query = TaskSchedulerQuery::create(['descricao' => 'Rotina', 'query_execute' => base64_encode('SELECT 1'), 'data_registro' => now()]);
    TaskSchedulerQueryLog::create(['fk_task_scheduler_querys' => $query->id, 'fk_c_cliente' => $cliente, 'log_query' => base64_encode('Erro: tabela inexistente'), 'data_time' => now()]);
    TaskSchedulerQueryLog::create(['fk_task_scheduler_querys' => $query->id, 'fk_c_cliente' => $cliente, 'log_query' => 'FALHA AO CONECTAR NO CLIENTE: sem rota', 'data_time' => now()->subMinute()]);

    Livewire::withQueryParams(['aba' => 'relatorio'])->test(Ferramentas::class)
        ->assertSee('Erro: tabela inexistente')->assertDontSee(base64_encode('Erro: tabela inexistente'))
        ->assertSee('FALHA AO CONECTAR NO CLIENTE: sem rota');
});

test('o robo executa a query criada pela tela (ida e volta)', function () {
    entrarParaFerramentas(true);
    session()->put('walle.senha_confirmada_em', now()->getTimestamp());
    config(['walle.tarefas.ativo' => true]);
    servidorDoClienteNoBancoDeTestes();
    $cliente = criarClienteHelpDesk();

    Livewire::test(Ferramentas::class)->set('descricao', 'Ida e volta')->set('queryExecute', 'SELECT 1 AS marca_ida_volta')->call('salvar');
    $query = TaskSchedulerQuery::firstOrFail();
    $query->update(['ativo' => 'S', 'deletado' => 'N']);

    $executados = [];
    DB::listen(function ($consulta) use (&$executados): void {
        if (str_contains($consulta->sql, 'marca_ida_volta')) {
            $executados[] = $consulta->sql;
        }
    });
    app(RoboTarefas::class)->rodarPendentes();

    $log = TaskSchedulerQueryLog::where('fk_c_cliente', $cliente)->firstOrFail();
    expect($executados)->toHaveCount(1)->and(base64_decode($log->log_query))->toBe('OK');
});

test('avisa quando o robo das tarefas esta desligado', function () {
    entrarParaFerramentas(false);
    config(['walle.tarefas.ativo' => false]);

    Livewire::test(Ferramentas::class)->assertSee('desligado');
});
