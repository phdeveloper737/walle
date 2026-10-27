<?php

use App\Contingencias\BuscaContingencias;
use App\Contingencias\RemocaoDeCliente;
use App\Livewire\Contingencias;
use App\Models\Auditoria;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/** Duas vendas por cliente, cada uma com 2 itens; devolve os ids das vendas. */
function vendasDoClienteComItens(int $clienteId): array
{
    $ids = [];

    foreach ([1, 2] as $n) {
        $venda = DB::table('contingencias_versatus_venda')->insertGetId([
            'fk_contingencias_versatus' => $clienteId,
            'id_venda' => $clienteId * 1000 + $n,
            'cupom' => (string) $n,
        ]);

        foreach ([1, 2] as $item) {
            DB::table('contingencias_versatus_venda_itens')->insert([
                'fk_contingencias_versatus_venda' => $venda,
                'nome' => "ITEM $item",
                'ncm' => '22021000',
                'cbenef' => '',
            ]);
        }

        $ids[] = $venda;
    }

    return $ids;
}

function adminDaLista(bool $admin = true, bool $senha = true): Usuario
{
    $usuario = Usuario::find(criarUsuarioHelpDesk());
    test()->actingAs($usuario);
    config(['walle.admins' => $admin ? [$usuario->id] : []]);

    if ($senha) {
        session()->put('walle.senha_confirmada_em', now()->getTimestamp());
    }

    return $usuario;
}

test('remover um cliente apaga só as vendas e os itens dele e devolve o total', function () {
    vendasDoClienteComItens(10);
    vendasDoClienteComItens(20);

    expect(app(RemocaoDeCliente::class)->remover(10))->toBe(6)
        ->and(DB::table('contingencias_versatus_venda')->where('fk_contingencias_versatus', 10)->count())->toBe(0)
        ->and(DB::table('contingencias_versatus_venda')->where('fk_contingencias_versatus', 20)->count())->toBe(2)
        ->and(DB::table('contingencias_versatus_venda_itens')->count())->toBe(4);
});

test('id inválido não apaga nada', function (int $id) {
    vendasDoClienteComItens(10);

    expect(app(RemocaoDeCliente::class)->remover($id))->toBe(0)
        ->and(DB::table('contingencias_versatus_venda')->count())->toBe(2)
        ->and(DB::table('contingencias_versatus_venda_itens')->count())->toBe(4);
})->with([0, -5, 999]);

test('a transação desfaz tudo se falhar no meio', function () {
    vendasDoClienteComItens(10);

    DB::listen(function ($consulta): void {
        if (str_starts_with($consulta->sql, 'delete from `contingencias_versatus_venda` ')) {
            throw new RuntimeException('falha simulada');
        }
    });

    expect(fn () => app(RemocaoDeCliente::class)->remover(10))->toThrow(RuntimeException::class)
        ->and(DB::table('contingencias_versatus_venda_itens')->count())->toBe(4)
        ->and(DB::table('contingencias_versatus_venda')->count())->toBe(2);
});

test('admin com senha confirmada remove o cliente e a auditoria registra a quantidade', function () {
    $admin = adminDaLista();
    vendasDoClienteComItens(10);
    vendasDoClienteComItens(20);

    Livewire::test(Contingencias::class)
        ->call('removerCliente', 10)
        ->assertSet('aviso', '6 registros de contingência do cliente #10 foram removidos.')
        ->assertSet('avisoErro', false);

    expect(DB::table('contingencias_versatus_venda')->where('fk_contingencias_versatus', 10)->count())->toBe(0)
        ->and(DB::table('contingencias_versatus_venda')->where('fk_contingencias_versatus', 20)->count())->toBe(2);

    $auditoria = Auditoria::where('acao', 'contingencias.remover_cliente')->sole();
    expect($auditoria->fk_c_cliente)->toBe(10)
        ->and($auditoria->usuario_id)->toBe($admin->id)
        ->and($auditoria->detalhes)->toBe(['apagadas' => 6]);
});

test('id inválido na tela avisa e não apaga nada', function (int $id) {
    adminDaLista();
    vendasDoClienteComItens(10);

    Livewire::test(Contingencias::class)
        ->call('removerCliente', $id)
        ->assertSet('avisoErro', true)
        ->assertSet('aviso', fn (?string $aviso): bool => $aviso !== null && $aviso !== '');

    expect(DB::table('contingencias_versatus_venda')->count())->toBe(2);
})->with([0, -5, 999]);

test('quem não é admin recebe 403 e nada é apagado', function () {
    adminDaLista(admin: false);
    vendasDoClienteComItens(10);

    Livewire::test(Contingencias::class)
        ->call('removerCliente', 10)
        ->assertForbidden();

    expect(DB::table('contingencias_versatus_venda')->count())->toBe(2)
        ->and(Auditoria::where('acao', 'contingencias.remover_cliente')->exists())->toBeFalse();
});

test('sem senha confirmada pede a senha e nada é apagado', function () {
    adminDaLista(senha: false);
    vendasDoClienteComItens(10);

    Livewire::test(Contingencias::class)
        ->call('removerCliente', 10)
        ->assertSet('pedindoSenha', true)
        ->assertSet('acaoPendente', 'removerCliente');

    expect(DB::table('contingencias_versatus_venda')->count())->toBe(2)
        ->and(Auditoria::where('acao', 'contingencias.remover_cliente')->exists())->toBeFalse();
});

test('depois de remover, o cache do cliente é esquecido e o filho é recriado', function () {
    adminDaLista();
    vendasDoClienteComItens(10);

    $busca = Mockery::mock(BuscaContingencias::class);
    $busca->shouldReceive('esquecer')->once()->with(10);
    app()->instance(BuscaContingencias::class, $busca);

    Livewire::test(Contingencias::class)
        ->assertSet('versaoDoCliente', 0)
        ->call('removerCliente', 10)
        ->assertSet('versaoDoCliente', 1);
});

test('trocar de cliente limpa o aviso', function () {
    adminDaLista();
    vendasDoClienteComItens(10);

    Livewire::test(Contingencias::class)
        ->call('removerCliente', 10)
        ->assertSet('aviso', fn (?string $a): bool => $a !== null)
        ->call('selecionar', 20)
        ->assertSet('aviso', null)
        ->assertSet('avisoErro', false);
});

test('com cliente selecionado, remover outro id é recusado', function () {
    adminDaLista();
    vendasDoClienteComItens(10);
    vendasDoClienteComItens(20);

    Livewire::test(Contingencias::class)
        ->call('selecionar', 20)
        ->call('removerCliente', 10)
        ->assertSet('avisoErro', true);

    expect(DB::table('contingencias_versatus_venda')->count())->toBe(4);
});

test('caminho completo: pede senha, confirma e remove', function () {
    adminDaLista(senha: false);
    vendasDoClienteComItens(10);

    Livewire::test(Contingencias::class)
        ->call('removerCliente', 10)
        ->assertSet('pedindoSenha', true)
        ->set('senhaConfirmacao', 'segredo')
        ->call('confirmarSenha')
        ->assertSet('pedindoSenha', false)
        ->assertSet('versaoDoCliente', 1);

    expect(DB::table('contingencias_versatus_venda')->count())->toBe(0)
        ->and(Auditoria::where('acao', 'contingencias.remover_cliente')->count())->toBe(1);
});

test('admin vê o botão de remover da lista de contingências e quem não é admin não vê', function (bool $admin) {
    adminDaLista(admin: $admin);
    $cliente = criarClienteHelpDesk();

    $teste = Livewire::test(Contingencias::class)->call('selecionar', $cliente);

    $admin
        ? $teste->assertSee('Remover da lista de contingências')
        : $teste->assertDontSee('Remover da lista de contingências');
})->with([true, false]);
