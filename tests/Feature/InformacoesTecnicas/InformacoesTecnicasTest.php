<?php

use App\InformacoesTecnicas\ConfiguracaoTecnica;
use App\Livewire\InformacoesTecnicas;
use App\Models\Auditoria;
use App\Models\InformacaoTecnicaPainelContingencias;
use App\Models\Usuario;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/** Entra como usuário do Help Desk; admin e senha confirmada são opcionais. */
function entrarNasInformacoesTecnicas(bool $admin = true, bool $senha = true): Usuario
{
    $usuario = Usuario::find(criarUsuarioHelpDesk());
    test()->actingAs($usuario);
    config(['walle.admins' => $admin ? [$usuario->id] : []]);

    if ($senha) {
        session()->put('walle.senha_confirmada_em', now()->getTimestamp());
    }

    return $usuario;
}

/**
 * Linha da configuração técnica do cliente no banco local (id = fk_c_cliente, como no 220).
 *
 * @param  array<string, mixed>  $extra
 */
function configuracaoTecnicaDoCliente(int $clienteId, array $extra = []): InformacaoTecnicaPainelContingencias
{
    return InformacaoTecnicaPainelContingencias::create(array_merge([
        'id' => $clienteId,
        'fk_c_cliente' => $clienteId,
        'razao_social' => 'MERCADO TESTE LTDA',
        'tips_server' => 'LO',
        'tips_pdv' => 'V',
        'ip_externo_servidor' => '200.1.2.3',
        'servidor_mysql' => '10.0.0.5',
        'porta_http' => '80',
        'porta_mysql' => '3306',
        'porta_ssh' => '22',
        'banco_mysql' => 'erp',
        'senha_mysql' => 'mysql-antiga-XYZ',
        'senha_ssh' => 'ssh-antiga-XYZ',
        'senha_ssh_docker' => 'docker-antiga-XYZ',
        'emissao_cupons' => 'S',
    ], $extra));
}

/**
 * Dados válidos de um servidor local (sem Docker).
 *
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function dadosTecnicosValidos(array $extra = []): array
{
    return array_merge([
        'ativo_painel_contingencias' => 'S',
        'tips_server' => 'LO',
        'tips_pdv' => 'A',
        'banco_separado_docker' => 'N',
        'ip_externo_servidor' => '201.10.20.30',
        'servidor_mysql' => '10.0.0.9',
        'porta_http' => '8080',
        'porta_mysql' => '3307',
        'porta_ssh' => '2222',
        'banco_mysql' => 'jm_erp',
    ], $extra);
}

test('a tela lista os clientes da configuração técnica e escolhe um', function () {
    entrarNasInformacoesTecnicas();
    $a = criarClienteHelpDesk(['razao_social' => 'ALFA SUPERMERCADO']);
    $b = criarClienteHelpDesk(['razao_social' => 'BETA ATACADO']);
    configuracaoTecnicaDoCliente($a, ['razao_social' => 'ALFA SUPERMERCADO']);
    configuracaoTecnicaDoCliente($b, ['razao_social' => 'BETA ATACADO', 'deletado' => 'S']);

    Livewire::test(InformacoesTecnicas::class)
        ->assertOk()
        ->assertSee('ALFA SUPERMERCADO')
        ->assertDontSee('BETA ATACADO')
        ->call('selecionar', $a)
        ->assertSet('clienteId', $a)
        ->assertSet('form.ip_externo_servidor', '200.1.2.3')
        ->assertSet('form.tips_server', 'LO')
        ->assertSee('12.345.678/0001-90');
});

test('sem clientes configurados a tela explica o vazio', function () {
    entrarNasInformacoesTecnicas();

    Livewire::test(InformacoesTecnicas::class)
        ->assertOk()
        ->assertSee('Nenhum cliente configurado');
});

test('cliente inexistente na URL mostra o vazio, sem erro', function () {
    entrarNasInformacoesTecnicas();

    Livewire::withQueryParams(['cliente' => 99999])
        ->test(InformacoesTecnicas::class)
        ->assertOk()
        ->assertSee('Escolha um cliente');
});

test('salvar grava a linha do cliente e reabrir mostra os mesmos valores, sem senhas na tela', function () {
    entrarNasInformacoesTecnicas();
    $id = criarClienteHelpDesk();
    configuracaoTecnicaDoCliente($id);

    Livewire::test(InformacoesTecnicas::class)
        ->call('selecionar', $id)
        ->set('form', dadosTecnicosValidos())
        ->call('salvar')
        ->assertHasNoErrors()
        ->assertSee('Configuração salva.');

    $linha = InformacaoTecnicaPainelContingencias::find($id);
    expect($linha->ip_externo_servidor)->toBe('201.10.20.30')
        ->and($linha->porta_http)->toBe('8080')
        ->and($linha->tips_pdv)->toBe('A')
        ->and($linha->banco_mysql)->toBe('jm_erp');

    Livewire::test(InformacoesTecnicas::class)
        ->call('selecionar', $id)
        ->assertSet('form.ip_externo_servidor', '201.10.20.30')
        ->assertSet('form.porta_mysql', '3307')
        ->assertSet('form.tips_pdv', 'A')
        ->assertSet('form.senha_mysql', '')
        ->assertSet('form.senha_ssh', '')
        ->assertSet('form.senha_ssh_docker', '')
        ->assertDontSee('mysql-antiga-XYZ')
        ->assertDontSee('ssh-antiga-XYZ')
        ->assertDontSee('docker-antiga-XYZ');
});

test('campo obrigatório vazio ou porta fora do intervalo mostra o erro e não grava', function (array $troca, string $campo) {
    entrarNasInformacoesTecnicas();
    $id = criarClienteHelpDesk();
    configuracaoTecnicaDoCliente($id);

    Livewire::test(InformacoesTecnicas::class)
        ->call('selecionar', $id)
        ->set('form', dadosTecnicosValidos($troca))
        ->call('salvar')
        ->assertHasErrors(["form.$campo"]);

    expect(InformacaoTecnicaPainelContingencias::find($id)->ip_externo_servidor)->toBe('200.1.2.3');
})->with([
    'servidor sem IP' => [['ip_externo_servidor' => ''], 'ip_externo_servidor'],
    'IP inválido' => [['ip_externo_servidor' => 'ab c;rm -rf'], 'ip_externo_servidor'],
    'host com espaço' => [['ip_externo_servidor' => 'jm consultorias.com.br'], 'ip_externo_servidor'],
    'porta zero' => [['porta_http' => '0'], 'porta_http'],
    'porta acima do limite' => [['porta_mysql' => '70000'], 'porta_mysql'],
    'porta com letra' => [['porta_ssh' => '22a'], 'porta_ssh'],
    'tipo de servidor inválido' => [['tips_server' => 'XX'], 'tips_server'],
    'banco vazio' => [['banco_mysql' => ''], 'banco_mysql'],
]);

test('quem não é admin recebe 403 ao salvar e ao importar', function () {
    entrarNasInformacoesTecnicas(admin: false);
    $id = criarClienteHelpDesk();
    configuracaoTecnicaDoCliente($id);

    Livewire::test(InformacoesTecnicas::class)
        ->call('selecionar', $id)
        ->set('form', dadosTecnicosValidos())
        ->call('salvar')
        ->assertForbidden();

    Livewire::test(InformacoesTecnicas::class)
        ->call('selecionar', $id)
        ->call('importar')
        ->assertForbidden();

    expect(InformacaoTecnicaPainelContingencias::find($id)->ip_externo_servidor)->toBe('200.1.2.3');
});

test('sem a senha confirmada nada grava; com a senha, grava e audita', function () {
    entrarNasInformacoesTecnicas(senha: false);
    $id = criarClienteHelpDesk();
    configuracaoTecnicaDoCliente($id);

    Livewire::test(InformacoesTecnicas::class)
        ->call('selecionar', $id)
        ->set('form', dadosTecnicosValidos())
        ->call('salvar')
        ->assertSet('pedindoSenha', true);

    expect(InformacaoTecnicaPainelContingencias::find($id)->ip_externo_servidor)->toBe('200.1.2.3')
        ->and(Auditoria::where('acao', 'informacoes_tecnicas.salvar')->count())->toBe(0);

    Livewire::test(InformacoesTecnicas::class)
        ->call('selecionar', $id)
        ->set('form', dadosTecnicosValidos())
        ->call('salvar')
        ->set('senhaConfirmacao', 'segredo')
        ->call('confirmarSenha')
        ->assertSet('pedindoSenha', false);

    expect(InformacaoTecnicaPainelContingencias::find($id)->ip_externo_servidor)->toBe('201.10.20.30')
        ->and(Auditoria::where('acao', 'informacoes_tecnicas.salvar')->count())->toBe(1);
});

test('senha em branco não apaga a existente e senha preenchida substitui', function () {
    entrarNasInformacoesTecnicas();
    $id = criarClienteHelpDesk();
    configuracaoTecnicaDoCliente($id);

    Livewire::test(InformacoesTecnicas::class)
        ->call('selecionar', $id)
        ->set('form', dadosTecnicosValidos(['senha_mysql' => '', 'senha_ssh' => '', 'senha_ssh_docker' => '']))
        ->call('salvar')
        ->assertHasNoErrors();

    $linha = InformacaoTecnicaPainelContingencias::find($id);
    expect($linha->senha_mysql)->toBe('mysql-antiga-XYZ')
        ->and($linha->senha_ssh)->toBe('ssh-antiga-XYZ')
        ->and($linha->senha_ssh_docker)->toBe('docker-antiga-XYZ');

    Livewire::test(InformacoesTecnicas::class)
        ->call('selecionar', $id)
        ->set('form', dadosTecnicosValidos(['senha_mysql' => 'nova-senha-ABC']))
        ->call('salvar')
        ->assertSet('form.senha_mysql', '')
        ->assertDontSee('nova-senha-ABC');

    $linha->refresh();
    expect($linha->senha_mysql)->toBe('nova-senha-ABC')
        ->and($linha->senha_ssh)->toBe('ssh-antiga-XYZ');
});

test('o serviço ignora coluna fora da lista fixa', function () {
    $id = criarClienteHelpDesk();
    configuracaoTecnicaDoCliente($id);

    app(ConfiguracaoTecnica::class)->salvar($id, dadosTecnicosValidos([
        'deletado' => 'S',
        'emissao_cupons' => 'N',
        'fk_c_cliente' => 999,
        'id' => 999,
        'razao_social' => 'INVASOR',
        'coluna_que_nao_existe' => 'x',
    ]));

    $linha = InformacaoTecnicaPainelContingencias::find($id);
    expect($linha->deletado)->toBe('N')
        ->and($linha->emissao_cupons)->toBe('S')
        ->and($linha->fk_c_cliente)->toBe($id)
        ->and($linha->razao_social)->toBe('MERCADO TESTE LTDA')
        ->and($linha->ip_externo_servidor)->toBe('201.10.20.30')
        ->and(InformacaoTecnicaPainelContingencias::count())->toBe(1);
});

test('o serviço recusa dados inválidos mesmo sem a tela', function () {
    $id = criarClienteHelpDesk();
    configuracaoTecnicaDoCliente($id);

    expect(fn () => app(ConfiguracaoTecnica::class)->salvar($id, dadosTecnicosValidos(['porta_ssh' => '65536'])))
        ->toThrow(ValidationException::class);

    expect(InformacaoTecnicaPainelContingencias::find($id)->porta_ssh)->toBe('22');
});

test('importar do Help Desk preserva tips e emissão de cupons e não grava no Help Desk', function () {
    entrarNasInformacoesTecnicas();
    $id = criarClienteHelpDesk(
        ['nome_fantasia' => 'MERCADO FANTASIA'],
        ['loja' => '7', 'servidor_mysql' => '10.9.9.9', 'banco_mysql' => 'banco_help', 'porta_mysql' => '3310', 'senha_mysql' => 'senha-do-help', 'ip_publico' => '177.1.1.1'],
    );
    configuracaoTecnicaDoCliente($id, ['tips_server' => 'VO', 'tips_pdv' => 'L', 'emissao_cupons' => 'S']);
    $helpAntes = DB::connection('help')->table('informacao_tecnica_dataserver')->where('fk_c_cliente', $id)->first();

    $consultas = [];
    DB::connection('help')->listen(function ($consulta) use (&$consultas) {
        $consultas[] = strtolower(ltrim($consulta->sql));
    });

    Livewire::test(InformacoesTecnicas::class)
        ->call('selecionar', $id)
        ->call('importar')
        ->assertSee('Configuração importada do Help Desk.');

    $linha = InformacaoTecnicaPainelContingencias::find($id);
    expect($linha->tips_server)->toBe('VO')
        ->and($linha->tips_pdv)->toBe('L')
        ->and($linha->emissao_cupons)->toBe('S')
        ->and($linha->id_loja)->toBe('7')
        ->and($linha->nome_fantasia)->toBe('MERCADO FANTASIA')
        ->and($linha->banco_mysql)->toBe('banco_help')
        ->and($linha->senha_mysql)->toBe('senha-do-help')
        ->and($linha->ip_publico)->toBe('177.1.1.1')
        ->and(InformacaoTecnicaPainelContingencias::count())->toBe(1)
        ->and($consultas)->not->toBeEmpty()
        ->and(array_values(array_filter($consultas, fn (string $sql): bool => ! str_starts_with($sql, 'select') && preg_match('/\b(into|update|from)\s+`?(c_cliente|informacao_tecnica_dataserver|a_user)`?(\s|$)/', $sql) === 1)))->toBe([])
        ->and(DB::connection('help')->table('informacao_tecnica_dataserver')->where('fk_c_cliente', $id)->first())->toEqual($helpAntes);
});

test('importar cliente novo usa os padrões do 220 para tips', function () {
    $id = criarClienteHelpDesk();

    app(ConfiguracaoTecnica::class)->importarDoHelpDesk($id);

    $linha = InformacaoTecnicaPainelContingencias::find($id);
    expect($linha->id)->toBe($id)
        ->and($linha->tips_server)->toBe('DO')
        ->and($linha->tips_pdv)->toBe('A')
        ->and($linha->emissao_cupons)->toBe('N');
});

test('a auditoria registra os nomes dos campos e nunca o valor das senhas', function () {
    entrarNasInformacoesTecnicas();
    $id = criarClienteHelpDesk();
    configuracaoTecnicaDoCliente($id);

    Livewire::test(InformacoesTecnicas::class)
        ->call('selecionar', $id)
        ->set('form', dadosTecnicosValidos(['senha_mysql' => 'nova-senha-ABC']))
        ->call('salvar')
        ->call('importar');

    $salvar = Auditoria::where('acao', 'informacoes_tecnicas.salvar')->sole();
    expect($salvar->fk_c_cliente)->toBe($id)
        ->and($salvar->detalhes['campos'])->toContain('ip_externo_servidor', 'senha_mysql')
        ->and(Auditoria::where('acao', 'informacoes_tecnicas.importar')->count())->toBe(1);

    $tudo = json_encode(Auditoria::all()->pluck('detalhes'));
    expect($tudo)->not->toContain('nova-senha-ABC')
        ->and($tudo)->not->toContain('mysql-antiga-XYZ');
});

test('o IP externo aceita nome de host, como jmconsultorias.com.br', function () {
    entrarNasInformacoesTecnicas();
    $id = criarClienteHelpDesk();
    configuracaoTecnicaDoCliente($id);

    Livewire::test(InformacoesTecnicas::class)
        ->call('selecionar', $id)
        ->set('form', dadosTecnicosValidos(['ip_externo_servidor' => 'jmconsultorias.com.br']))
        ->call('salvar')
        ->assertHasNoErrors();

    expect(InformacaoTecnicaPainelContingencias::find($id)->ip_externo_servidor)->toBe('jmconsultorias.com.br');
});

test('cliente LL com valor legado do Docker salva e o valor legado fica como está', function () {
    entrarNasInformacoesTecnicas();
    $id = criarClienteHelpDesk();
    configuracaoTecnicaDoCliente($id, ['tips_server' => 'LL', 'porta_ssh_container_docker' => 'root', 'porta_http_docker' => '000']);

    Livewire::test(InformacoesTecnicas::class)
        ->call('selecionar', $id)
        ->assertSet('form.porta_ssh_container_docker', 'root')
        ->set('form', array_merge(dadosTecnicosValidos(['tips_server' => 'LL']), ['porta_ssh_container_docker' => 'root', 'porta_http_docker' => '000']))
        ->call('salvar')
        ->assertHasNoErrors()
        ->assertSee('Configuração salva.');

    $linha = InformacaoTecnicaPainelContingencias::find($id);
    expect($linha->porta_ssh_container_docker)->toBe('root')
        ->and($linha->porta_http_docker)->toBe('000')
        ->and($linha->ip_externo_servidor)->toBe('201.10.20.30');
});

test('servidor Docker exige os campos do Docker', function () {
    entrarNasInformacoesTecnicas();
    $id = criarClienteHelpDesk();
    configuracaoTecnicaDoCliente($id);

    Livewire::test(InformacoesTecnicas::class)
        ->call('selecionar', $id)
        ->set('form', dadosTecnicosValidos(['tips_server' => 'DO', 'ip_docker_principal' => '192.168.10.244', 'ip_container_docker' => '10.0.0.2', 'porta_http_docker' => '80', 'porta_mysql_docker' => '3306', 'porta_ssh_docker' => '22', 'porta_ssh_container_docker' => '']))
        ->call('salvar')
        ->assertHasErrors(['form.porta_ssh_container_docker']);

    expect(InformacaoTecnicaPainelContingencias::find($id)->tips_server)->toBe('LO');
});

test('falha na gravação local não manda senha para o log nem para a tela', function (string $acao) {
    entrarNasInformacoesTecnicas();
    $id = criarClienteHelpDesk([], ['senha_mysql' => 'senha-do-help-SECRETA']);
    configuracaoTecnicaDoCliente($id);
    Exceptions::fake();

    InformacaoTecnicaPainelContingencias::saving(function () {
        throw new QueryException('mysql', 'update `informacao_tecnica_painel_contingencias` set `senha_mysql` = ?', ['nova-senha-SECRETA'], new Exception('Data too long'));
    });

    try {
        Livewire::test(InformacoesTecnicas::class)
            ->call('selecionar', $id)
            ->set('form', dadosTecnicosValidos(['senha_mysql' => 'nova-senha-SECRETA']))
            ->call($acao)
            ->assertSee('Não foi possível gravar no banco do walle')
            ->assertDontSee('Help Desk (192.168.10.254) não respondeu')
            ->assertDontSee('SECRETA');
    } finally {
        InformacaoTecnicaPainelContingencias::flushEventListeners();
    }

    Exceptions::assertNothingReported();
    expect(Auditoria::where('acao', 'like', 'informacoes_tecnicas.%')->count())->toBe(0)
        ->and(InformacaoTecnicaPainelContingencias::find($id)->senha_mysql)->toBe('mysql-antiga-XYZ');
})->with(['salvar', 'importar']);

test('a importação não reativa o cliente desativado e usa o padrão em linha nova', function () {
    entrarNasInformacoesTecnicas();
    $id = criarClienteHelpDesk();
    configuracaoTecnicaDoCliente($id, ['ativo_painel_contingencias' => 'N']);
    $novo = criarClienteHelpDesk();

    Livewire::test(InformacoesTecnicas::class)->call('selecionar', $id)->call('importar')->assertSee('Configuração importada');
    app(ConfiguracaoTecnica::class)->importarDoHelpDesk($novo);

    expect(InformacaoTecnicaPainelContingencias::find($id)->ativo_painel_contingencias)->toBe('N')
        ->and(InformacaoTecnicaPainelContingencias::find($novo)->ativo_painel_contingencias)->toBe('S');
});

test('senha vazia no Help Desk mantém a senha local na importação', function () {
    $id = criarClienteHelpDesk([], ['senha_mysql' => '', 'senha_ssh' => '', 'senha_ssh_docker' => '', 'senha_mysql_docker' => '']);
    configuracaoTecnicaDoCliente($id, ['senha_mysql_docker' => 'mysql-docker-antiga']);

    app(ConfiguracaoTecnica::class)->importarDoHelpDesk($id);

    $linha = InformacaoTecnicaPainelContingencias::find($id);
    expect($linha->senha_mysql)->toBe('mysql-antiga-XYZ')
        ->and($linha->senha_ssh)->toBe('ssh-antiga-XYZ')
        ->and($linha->senha_ssh_docker)->toBe('docker-antiga-XYZ')
        ->and($linha->senha_mysql_docker)->toBe('mysql-docker-antiga');
});

test('salvar preserva os espaços que fazem parte das senhas', function () {
    entrarNasInformacoesTecnicas();
    $id = criarClienteHelpDesk();
    configuracaoTecnicaDoCliente($id);

    Livewire::test(InformacoesTecnicas::class)
        ->call('selecionar', $id)
        ->set('form', dadosTecnicosValidos([
            'senha_mysql' => ' mysql-nova ',
            'senha_ssh' => ' ssh-nova ',
            'senha_ssh_docker' => ' docker-nova ',
        ]))
        ->call('salvar')
        ->assertHasNoErrors()
        ->assertSet('form.senha_mysql', '')
        ->assertSet('form.senha_ssh', '')
        ->assertSet('form.senha_ssh_docker', '');

    $linha = InformacaoTecnicaPainelContingencias::find($id);
    expect($linha->senha_mysql)->toBe(' mysql-nova ')
        ->and($linha->senha_ssh)->toBe(' ssh-nova ')
        ->and($linha->senha_ssh_docker)->toBe(' docker-nova ');
});

test('importar preserva os espaços das quatro senhas do Help Desk', function () {
    $id = criarClienteHelpDesk([], [
        'senha_mysql' => ' mysql-help ',
        'senha_ssh' => ' ssh-help ',
        'senha_ssh_docker' => ' ssh-docker-help ',
        'senha_mysql_docker' => ' mysql-docker-help ',
    ]);
    configuracaoTecnicaDoCliente($id);

    app(ConfiguracaoTecnica::class)->importarDoHelpDesk($id);

    $linha = InformacaoTecnicaPainelContingencias::find($id);
    expect($linha->senha_mysql)->toBe(' mysql-help ')
        ->and($linha->senha_ssh)->toBe(' ssh-help ')
        ->and($linha->senha_ssh_docker)->toBe(' ssh-docker-help ')
        ->and($linha->senha_mysql_docker)->toBe(' mysql-docker-help ');
});

test('a confirmação de senha fica dentro da raiz da tela ao salvar ou importar', function (string $acao, ?int $confirmadaHaMinutos) {
    $this->freezeTime();
    entrarNasInformacoesTecnicas(senha: false);
    session()->put('walle.senha_confirmada_em', $confirmadaHaMinutos === null ? null : now()->subMinutes($confirmadaHaMinutos)->getTimestamp());
    config(['walle.confirmar_senha_minutos' => 5]);
    $id = criarClienteHelpDesk();
    configuracaoTecnicaDoCliente($id);

    $tela = Livewire::test(InformacoesTecnicas::class)
        ->call('selecionar', $id)
        ->set('form', dadosTecnicosValidos())
        ->call($acao)
        ->assertSet('pedindoSenha', true);

    $documento = new DOMDocument;
    $documento->loadHTML($tela->html(), LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($documento);

    expect($xpath->evaluate('count(/html/body/*[1]//*[@role="dialog" and @aria-modal="true"])'))->toBe(1.0)
        ->and($xpath->evaluate('count(/html/body/*)'))->toBe(1.0);
    expect(InformacaoTecnicaPainelContingencias::find($id)->ip_externo_servidor)->toBe('200.1.2.3')
        ->and(Auditoria::where('acao', 'like', 'informacoes_tecnicas.%')->count())->toBe(0);
})->with([
    'salvar sem confirmação' => ['salvar', null],
    'salvar com confirmação expirada' => ['salvar', 6],
    'importar sem confirmação' => ['importar', null],
    'importar com confirmação expirada' => ['importar', 6],
]);

test('os campos Docker aceitam nome de host, como jmconsultorias.com.br', function () {
    entrarNasInformacoesTecnicas();
    $id = criarClienteHelpDesk();
    configuracaoTecnicaDoCliente($id);

    Livewire::test(InformacoesTecnicas::class)
        ->call('selecionar', $id)
        ->set('form', dadosTecnicosValidos([
            'tips_server' => 'DO',
            'ip_docker_principal' => 'jmconsultorias.com.br',
            'ip_container_docker' => 'container.jmconsultorias.com.br',
            'porta_http_docker' => '80',
            'porta_mysql_docker' => '3306',
            'porta_ssh_docker' => '22',
            'porta_ssh_container_docker' => '2222',
        ]))
        ->call('salvar')
        ->assertHasNoErrors();

    $linha = InformacaoTecnicaPainelContingencias::find($id);
    expect($linha->ip_docker_principal)->toBe('jmconsultorias.com.br')
        ->and($linha->ip_container_docker)->toBe('container.jmconsultorias.com.br')
        ->and($linha->tips_server)->toBe('DO');
});

test('os campos Docker continuam recusando valor que não é IP nem nome de host', function (string $campo) {
    entrarNasInformacoesTecnicas();
    $id = criarClienteHelpDesk();
    configuracaoTecnicaDoCliente($id);

    Livewire::test(InformacoesTecnicas::class)
        ->call('selecionar', $id)
        ->set('form', dadosTecnicosValidos([
            'tips_server' => 'DO',
            'ip_docker_principal' => 'jmconsultorias.com.br',
            'ip_container_docker' => '10.0.0.2',
            'porta_http_docker' => '80',
            'porta_mysql_docker' => '3306',
            'porta_ssh_docker' => '22',
            'porta_ssh_container_docker' => '2222',
            $campo => 'ab c;rm -rf',
        ]))
        ->call('salvar')
        ->assertHasErrors(["form.$campo"]);

    expect(InformacaoTecnicaPainelContingencias::find($id)->tips_server)->toBe('LO');
})->with(['ip_docker_principal', 'ip_container_docker']);

test('falha de leitura do Help Desk na importação mostra a mensagem do Help Desk', function () {
    entrarNasInformacoesTecnicas();
    $id = criarClienteHelpDesk();
    configuracaoTecnicaDoCliente($id);

    $tela = Livewire::test(InformacoesTecnicas::class)->call('selecionar', $id);

    DB::connection('help')->listen(function ($consulta) use ($id) {
        if ($consulta->connectionName !== 'help') {
            return;
        }

        throw new QueryException('help', 'select * from `c_cliente` where `c`.`id` = ?', [$id], new PDOException('Connection refused'));
    });

    $tela->call('importar')
        ->assertSee('O Help Desk (192.168.10.254) não respondeu. Nada foi importado; tente de novo em instantes.')
        ->assertDontSee('Não foi possível gravar no banco do walle');

    expect(InformacaoTecnicaPainelContingencias::find($id)->ip_externo_servidor)->toBe('200.1.2.3')
        ->and(Auditoria::where('acao', 'informacoes_tecnicas.importar')->count())->toBe(0);
});

test('falha no SELECT local da importação mostra a mensagem local, não a do Help Desk', function () {
    entrarNasInformacoesTecnicas();
    $id = criarClienteHelpDesk();
    configuracaoTecnicaDoCliente($id);
    Exceptions::fake();

    $tela = Livewire::test(InformacoesTecnicas::class)->call('selecionar', $id);

    DB::listen(function ($consulta) use ($id) {
        if (str_contains($consulta->sql, 'informacao_tecnica_painel_contingencias')
            && str_contains($consulta->sql, 'fk_c_cliente')
            && ! str_contains($consulta->sql, 'deletado')
            && ! str_contains($consulta->sql, 'order by')) {
            throw new QueryException('mysql', 'select * from informacao_tecnica_painel_contingencias where fk_c_cliente = ?', [$id], new PDOException('Lock wait timeout'));
        }
    });

    $tela->call('importar')
        ->assertSee('Não foi possível ler a configuração do cliente no banco do walle')
        ->assertDontSee('Nada foi importado')
        ->assertDontSee('O Help Desk (192.168.10.254) não respondeu');

    Exceptions::assertNothingReported();
    expect(InformacaoTecnicaPainelContingencias::find($id)->senha_mysql)->toBe('mysql-antiga-XYZ')
        ->and(Auditoria::where('acao', 'informacoes_tecnicas.importar')->count())->toBe(0);
});

test('campo de IP em array mostra erro de validação, sem estourar a tela', function (string $campo) {
    entrarNasInformacoesTecnicas();
    $id = criarClienteHelpDesk();
    configuracaoTecnicaDoCliente($id);

    Livewire::test(InformacoesTecnicas::class)
        ->call('selecionar', $id)
        ->set('form', dadosTecnicosValidos([
            'tips_server' => 'DO',
            'ip_docker_principal' => '192.168.10.244',
            'ip_container_docker' => '10.0.0.2',
            'porta_http_docker' => '80',
            'porta_mysql_docker' => '3306',
            'porta_ssh_docker' => '22',
            'porta_ssh_container_docker' => '2222',
        ]))
        ->set("form.$campo", ['invalido'])
        ->call('salvar')
        ->assertHasErrors(["form.$campo"]);

    expect(InformacaoTecnicaPainelContingencias::find($id)->tips_server)->toBe('LO');
})->with(['ip_docker_principal', 'ip_container_docker']);

test('o erro do campo Docker fala no endereço do contêiner', function () {
    entrarNasInformacoesTecnicas();
    $id = criarClienteHelpDesk();
    configuracaoTecnicaDoCliente($id);

    Livewire::test(InformacoesTecnicas::class)
        ->call('selecionar', $id)
        ->set('form', dadosTecnicosValidos([
            'tips_server' => 'DO',
            'ip_docker_principal' => '192.168.10.244',
            'ip_container_docker' => 'ab c;rm -rf',
            'porta_http_docker' => '80',
            'porta_mysql_docker' => '3306',
            'porta_ssh_docker' => '22',
            'porta_ssh_container_docker' => '2222',
        ]))
        ->call('salvar')
        ->assertHasErrors(['form.ip_container_docker'])
        ->assertSee('no endereço do contêiner (Docker)');
});

test('senha só com espaços conta como cadastrada e não vai para a tela', function () {
    entrarNasInformacoesTecnicas();
    $id = criarClienteHelpDesk();
    configuracaoTecnicaDoCliente($id, [
        'tips_server' => 'DO',
        'senha_mysql' => '   ',
        'senha_ssh' => ' ',
        'senha_ssh_docker' => '  ',
    ]);

    Livewire::test(InformacoesTecnicas::class)
        ->call('selecionar', $id)
        ->assertSee('Senha cadastrada. Preencha para substituir.')
        ->assertDontSee('Nenhuma senha cadastrada');
});
