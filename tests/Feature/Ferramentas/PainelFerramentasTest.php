<?php

use App\Livewire\Ferramentas;
use App\Models\Auditoria;
use App\Models\TaskSchedulerQuery;
use App\Models\TaskSchedulerQueryLog;
use App\Models\Usuario;
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

test('avisa quando o robo das tarefas esta desligado', function () {
    entrarParaFerramentas(false);
    config(['walle.tarefas.ativo' => false]);

    Livewire::test(Ferramentas::class)->assertSee('desligado');
});
