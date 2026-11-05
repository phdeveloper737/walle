<?php

use App\Livewire\Autorizadas;
use App\Livewire\CleanTax;
use App\Livewire\Contingencias;
use App\Livewire\Ferramentas;
use App\Livewire\Robos\Configuracao;
use App\Livewire\Robos\Historico;
use App\Livewire\Sactus;
use App\Livewire\Sentinela;
use App\Models\Auditoria;
use App\Models\RoboConfig;
use App\Models\RoboConfigHistorico;
use App\Models\Usuario;
use App\Support\ConfiguracaoRobo;
use Livewire\Livewire;

function telaRoboComoAdmin(bool $admin = true, bool $senhaConfirmada = true): Usuario
{
    $usuario = Usuario::find(criarUsuarioHelpDesk());
    test()->actingAs($usuario);
    config(['walle.admins' => $admin ? [$usuario->id] : []]);

    if ($senhaConfirmada) {
        session()->put('walle.senha_confirmada_em', now()->getTimestamp());
    }

    return $usuario;
}

dataset('paineis de robô', [
    'autorizadas' => [Autorizadas::class, 'autorizadas'],
    'contingencias' => [Contingencias::class, 'contingencias'],
    'sentinela' => [Sentinela::class, 'sentinela'],
    'cleantax' => [CleanTax::class, 'cleantax'],
    'ferramentas' => [Ferramentas::class, 'tarefas'],
    'sactus' => [Sactus::class, 'sactus'],
]);

test('cada painel abre as abas configuração e histórico por URL', function (string $componente, string $robo) {
    telaRoboComoAdmin();

    Livewire::withQueryParams(['aba' => 'configuracao'])->test($componente)
        ->assertOk()
        ->assertSet('aba', 'configuracao')
        ->assertSee('Execução do robô')
        ->assertSee('Parâmetros');

    Livewire::withQueryParams(['aba' => 'historico'])->test($componente)
        ->assertOk()
        ->assertSet('aba', 'historico')
        ->assertSee('Alterações de configuração')
        ->assertSee('Nenhuma alteração no período');
})->with('paineis de robô');

test('aba inválida volta para a aba padrão de cada painel', function (string $componente, string $padrao) {
    telaRoboComoAdmin();

    Livewire::test($componente, ['aba' => 'inexistente'])->assertSet('aba', $padrao);
})->with([
    'autorizadas' => [Autorizadas::class, 'robo'],
    'contingencias' => [Contingencias::class, 'clientes'],
    'sentinela' => [Sentinela::class, 'painel'],
    'cleantax' => [CleanTax::class, 'painel'],
    'ferramentas' => [Ferramentas::class, 'querys'],
    'sactus' => [Sactus::class, 'clientes'],
]);

test('admin com senha confirmada altera um parâmetro e fica no histórico sem segredo', function () {
    telaRoboComoAdmin(senhaConfirmada: false);

    Livewire::test(Configuracao::class, ['robo' => 'autorizadas'])
        ->call('iniciarEdicao', 'limite_ciclo')
        ->set('valorEditado', '300')
        ->call('salvarParametro')
        ->assertSet('pedindoSenha', true)
        ->set('senhaConfirmacao', 'segredo')
        ->call('confirmarSenha')
        ->assertSee('Configuração salva');

    expect(ConfiguracaoRobo::persistido('autorizadas', 'limite_ciclo'))->toBe(300)
        ->and(RoboConfigHistorico::query()->where('robo', 'autorizadas')->count())->toBe(1)
        ->and(Auditoria::query()->where('acao', 'robo.config.alterar')->count())->toBe(1);

    $historico = RoboConfigHistorico::query()->sole();
    expect($historico->parametro)->toBe('limite_ciclo')
        ->and($historico->valor_novo_sanitizado)->toBe('300')
        ->and(json_encode(json_encode($historico->toArray())))->not->toContain('segredo');
});

test('admin liga e desliga a execução local, com histórico e auditoria', function () {
    config(['walle.autorizadas.ativo' => true]);
    telaRoboComoAdmin(senhaConfirmada: false);

    Livewire::test(Configuracao::class, ['robo' => 'autorizadas'])
        ->call('alternarAtivo')
        ->assertSet('pedindoSenha', true)
        ->set('senhaConfirmacao', 'segredo')
        ->call('confirmarSenha')
        ->assertSee('Execução local ligada');

    expect(ConfiguracaoRobo::ativoLocal('autorizadas'))->toBeTrue()
        ->and(ConfiguracaoRobo::ligado('autorizadas'))->toBeTrue()
        ->and(RoboConfigHistorico::query()->where('acao', 'ativar')->count())->toBe(1)
        ->and(Auditoria::query()->where('acao', 'robo.config.ativar')->count())->toBe(1);

    Livewire::test(Configuracao::class, ['robo' => 'autorizadas'])->call('alternarAtivo')->assertSee('Execução local desligada');

    expect(ConfiguracaoRobo::ativoLocal('autorizadas'))->toBeFalse()
        ->and(RoboConfigHistorico::query()->where('acao', 'desativar')->count())->toBe(1)
        ->and(Auditoria::query()->where('acao', 'robo.config.desativar')->count())->toBe(1);
});

test('quem não é admin recebe 403 e nada é persistido', function () {
    telaRoboComoAdmin(admin: false);

    Livewire::test(Configuracao::class, ['robo' => 'sentinela'])->call('iniciarEdicao', 'ativo')->assertForbidden();
    Livewire::test(Configuracao::class, ['robo' => 'sentinela'])->set('valorEditado', '1')->call('salvarParametro')->assertForbidden();
    Livewire::test(Configuracao::class, ['robo' => 'sentinela'])->call('alternarAtivo')->assertForbidden();

    expect(RoboConfig::query()->count())->toBe(0)
        ->and(RoboConfigHistorico::query()->count())->toBe(0)
        ->and(Auditoria::query()->count())->toBe(0);
});

test('parâmetro inválido e valor fora da fronteira são recusados sem gravar', function () {
    telaRoboComoAdmin();

    Livewire::test(Configuracao::class, ['robo' => 'autorizadas'])
        ->call('iniciarEdicao', 'nao_existe')
        ->assertSet('editando', null)
        ->call('salvarParametro');

    Livewire::test(Configuracao::class, ['robo' => 'autorizadas'])
        ->call('iniciarEdicao', 'limite_ciclo')
        ->set('valorEditado', '10001')
        ->call('salvarParametro')
        ->assertSee('fora dos limites');

    Livewire::test(Configuracao::class, ['robo' => 'autorizadas'])
        ->call('iniciarEdicao', 'limite_ciclo')
        ->set('valorEditado', 'abc')
        ->call('salvarParametro')
        ->assertSee('formato esperado');

    expect(RoboConfig::query()->count())->toBe(0)
        ->and(RoboConfigHistorico::query()->count())->toBe(0);
});

test('sem a senha confirmada a mudança só abre a janela e não grava', function () {
    telaRoboComoAdmin(senhaConfirmada: false);

    Livewire::test(Configuracao::class, ['robo' => 'contingencias'])
        ->call('iniciarEdicao', 'ativo')
        ->call('alternarAtivo')
        ->assertSet('pedindoSenha', true);

    Livewire::test(Configuracao::class, ['robo' => 'contingencias'])
        ->call('alternarAtivo')
        ->assertSet('pedindoSenha', true);

    expect(RoboConfig::query()->count())->toBe(0)
        ->and(RoboConfigHistorico::query()->count())->toBe(0);
});

test('segredos aparecem mascarados e não abrem para edição', function () {
    config(['walle.cleantax.token' => 'TOKEN-SUPER-SECRETO']);
    telaRoboComoAdmin();

    Livewire::test(Configuracao::class, ['robo' => 'cleantax'])
        ->assertSee('Token da CleanTax')
        ->assertSee('Só no .env')
        ->assertDontSee('TOKEN-SUPER-SECRETO')
        ->call('iniciarEdicao', 'token')
        ->assertSet('editando', null);
});

test('histórico lista e filtra por período, parâmetro, ação e ator', function () {
    telaRoboComoAdmin();
    ConfiguracaoRobo::gravar('autorizadas', 'limite_ciclo', 300, 'maria');
    ConfiguracaoRobo::gravar('autorizadas', 'pausa_entre_chaves', 2, 'joao');
    ConfiguracaoRobo::definirAtivo('autorizadas', true, 'maria');

    Livewire::test(Historico::class, ['robo' => 'autorizadas'])
        ->assertSee('limite_ciclo')
        ->assertSee('pausa_entre_chaves')
        ->assertSee('Ligado')
        ->set('parametro', 'limite_ciclo')
        ->assertSee('limite_ciclo')
        ->assertDontSee('pausa_entre_chaves')
        ->set('parametro', '')
        ->set('acao', 'ativar')
        ->assertSee('Ligado')
        ->assertDontSee('pausa_entre_chaves')
        ->set('acao', '')
        ->set('ator', 'joao')
        ->assertSee('joao')
        ->assertSee('pausa_entre_chaves')
        ->assertDontSee('limite_ciclo')
        ->set('ator', '')
        ->set('periodo', 'todos')
        ->assertSee('limite_ciclo');
});

test('histórico com filtro inválido não quebra e mostra o vazio', function () {
    telaRoboComoAdmin();

    Livewire::test(Historico::class, ['robo' => 'sentinela', 'periodo' => 'xpto', 'acao' => 'xpto'])
        ->assertOk()
        ->assertSet('periodo', '30')
        ->assertSet('acao', '')
        ->assertSee('Nenhuma alteração no período');

    Livewire::test(Historico::class, ['robo' => 'sentinela'])
        ->set('periodo', 'xpto')
        ->assertSet('periodo', '30')
        ->set('acao', 'xpto')
        ->assertSet('acao', '')
        ->assertSee('Nenhuma alteração no período');
});

test('histórico pagina as edições', function () {
    telaRoboComoAdmin();

    for ($i = 1; $i <= 16; $i++) {
        ConfiguracaoRobo::gravar('autorizadas', 'pausa_entre_chaves', $i, 'maria');
    }

    Livewire::test(Historico::class, ['robo' => 'autorizadas'])
        ->assertSee('1–15 de 16')
        ->call('irParaPagina', 2)
        ->assertSee('16–16 de 16');
});

test('com env ligado e chave do painel desligada, cada painel mostra a causa certa', function (string $componente, string $robo, string $aba, string $texto) {
    config(["walle.{$robo}.ativo" => true]);
    ConfiguracaoRobo::definirAtivo($robo, false, 'teste');
    telaRoboComoAdmin();

    Livewire::test($componente, ['aba' => $aba])->assertOk()->assertSee($texto);

    Livewire::test(Configuracao::class, ['robo' => $robo])
        ->assertSee('desligado na configuração local')
        ->assertSee('Ligar execução local');
})->with([
    'sentinela' => [Sentinela::class, 'sentinela', 'painel', 'chave "ativo" do robô'],
    'cleantax' => [CleanTax::class, 'cleantax', 'painel', 'chave "ativo" desligada no painel'],
    'contingencias' => [Contingencias::class, 'contingencias', 'robo', 'painel desligado'],
    'sactus' => [Sactus::class, 'sactus', 'clientes', 'chave "ativo" desligada no painel'],
    'autorizadas' => [Autorizadas::class, 'autorizadas', 'robo', 'chave "ativo" na aba Configuração'],
    'tarefas' => [Ferramentas::class, 'tarefas', 'querys', 'chave "ativo" desligada no painel'],
]);

test('com a chave local ligada e o env desligado, a configuração mostra o corte de segurança', function (string $componente, string $robo) {
    config(["walle.{$robo}.ativo" => false]);
    ConfiguracaoRobo::definirAtivo($robo, true, 'teste');
    telaRoboComoAdmin();

    Livewire::test(Configuracao::class, ['robo' => $robo])
        ->assertSee('WALLE_'.strtoupper($robo).'_ATIVO=false')
        ->assertSee('impede a execução')
        ->assertSee('Desligar execução local');

    expect(ConfiguracaoRobo::ligado($robo))->toBeFalse();
})->with([
    'autorizadas' => [Autorizadas::class, 'autorizadas'],
    'contingencias' => [Contingencias::class, 'contingencias'],
    'sentinela' => [Sentinela::class, 'sentinela'],
    'cleantax' => [CleanTax::class, 'cleantax'],
    'tarefas' => [Ferramentas::class, 'tarefas'],
    'sactus' => [Sactus::class, 'sactus'],
]);
