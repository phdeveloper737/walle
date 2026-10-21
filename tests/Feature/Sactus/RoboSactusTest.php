<?php

use App\Jobs\RodarCicloSactus;
use App\Models\CicloSactus;
use App\Models\PainelSactusCfg;
use App\Models\SactusCfgEdicao;
use App\Sactus\RoboSactus;
use App\Sactus\Ssh\ResultadoComando;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-21 10:00:00'));
    config(['walle.sactus.erp.senha' => 'senha-do-220']);
});

function painelDe(int $clienteId): PainelSactusCfg
{
    return PainelSactusCfg::query()->where('fk_c_cliente', $clienteId)->firstOrFail();
}

function pedidoDeEdicao(int $clienteId, string $tipo, string $flag, string $valor = '', array $extra = []): SactusCfgEdicao
{
    return SactusCfgEdicao::create(array_merge([
        'fk_c_cliente' => $clienteId, 'tipo_edicao' => $tipo, 'flag' => $flag, 'valor' => $valor,
        'solicitante' => 'Maria', 'criado_em' => now(),
    ], $extra));
}

test('coleta o sactus.cfg, grava o painel e registra o ciclo', function () {
    $cliente = criarClienteSactus();
    sshFalso()->servidor('200.1.2.3', 22, 'senha-ssh', ['/etc/sactus.cfg' => cfgSactus()]);

    $ciclo = app(RoboSactus::class)->rodarCiclo();

    expect($ciclo->only(['elegiveis', 'coletados', 'falhas', 'offline', 'sem_credenciais']))
        ->toBe(['elegiveis' => 1, 'coletados' => 1, 'falhas' => 0, 'offline' => 0, 'sem_credenciais' => 0])
        ->and($ciclo->terminado_em)->not->toBeNull();

    $p = painelDe($cliente);
    expect($p->only(['status', 'metodo', 'host', 'porta', 'usuario', 'tentativas', 'acl_status', 'erp_status']))
        ->toBe(['status' => 'S', 'metodo' => 'NORMAL', 'host' => '200.1.2.3', 'porta' => 22, 'usuario' => 'root', 'tentativas' => 1, 'acl_status' => 'X', 'erp_status' => 'X'])
        ->and($p->conteudo)->toBe(cfgSactus())
        ->and($p->flags())->toMatchArray(['licenciado' => 'S', 'debug' => 'N'])
        ->and($p->data->toDateTimeString())->toBe('2026-10-21 10:00:00');
});

test('fora do ar na pré-checagem, sem credencial e senha recusada viram falha com o motivo', function () {
    $foraDoAr = criarClienteSactus(['ip_externo_servidor' => '200.9.9.9']);
    $semSenha = criarClienteSactus(['senha_ssh' => '']);
    $senhaErrada = criarClienteSactus(['ip_externo_servidor' => '200.5.5.5']);
    sshFalso(['200.9.9.9|22'])->servidor('200.5.5.5', 22, 'outra-senha');

    $ciclo = app(RoboSactus::class)->rodarCiclo();

    expect($ciclo->only(['elegiveis', 'offline', 'sem_credenciais', 'falhas', 'coletados']))
        ->toBe(['elegiveis' => 3, 'offline' => 1, 'sem_credenciais' => 1, 'falhas' => 1, 'coletados' => 0])
        ->and(painelDe($foraDoAr)->erro)->toBe('Host inacessivel no pre-check (porta TCP): 200.9.9.9:22')
        ->and(painelDe($semSenha)->erro)->toStartWith('Nenhum alvo SSH valido para o tipo NORMAL')
        ->and(painelDe($senhaErrada)->erro)->toBe('NORMAL 200.5.5.5:22 => autenticacao recusada (usuario/senha)');
});

test('se o primeiro alvo falha, tenta o próximo (Docker → externo)', function () {
    $cliente = criarClienteSactus(['pasta_multiempresa' => 'S', 'servidor_docker' => '192.168.10.244', 'porta_ssh_docker' => '2207',
        'usuario_ssh_docker' => 'root', 'senha_ssh_docker' => 'senha-docker']);
    sshFalso()->servidor('200.1.2.3', 22, 'senha-ssh', ['/etc/sactus.cfg' => cfgSactus()]);

    app(RoboSactus::class)->rodarCiclo();

    expect(painelDe($cliente)->only(['status', 'metodo', 'host', 'tentativas']))->toBe(['status' => 'S', 'metodo' => 'NORMAL', 'host' => '200.1.2.3', 'tentativas' => 3]);
});

test('ACL: aplica nos arquivos, instala o pacote acl se faltar e NUNCA roda o yum update sozinho', function () {
    $cliente = criarClienteSactus(['service_acl' => 'S']);
    $ssh = sshFalso()->servidor('200.1.2.3', 22, 'senha-ssh', ['/etc/sactus.cfg' => cfgSactus(), '/var/sistema/config.ini' => 'x=1'], setfacl: false);

    $ciclo = app(RoboSactus::class)->rodarCiclo();
    $p = painelDe($cliente);

    expect($p->only(['acl_status', 'acl_acao', 'acl_erro']))->toBe(['acl_status' => 'S', 'acl_acao' => 'aplicada-pos-instalacao', 'acl_erro' => ''])
        ->and(array_column($p->itensAcl(), 'arquivo'))->toBe(['/var/sistema/config.ini', '/etc/sactus.cfg'])
        ->and($ciclo->acl_ok)->toBe(1)
        ->and($ssh->comandosEm('200.1.2.3', 22))->toContain('yum install -y acl')->not->toContain('yum -y update');

    // No ciclo seguinte já está aplicada: não instala nem aplica de novo
    app(RoboSactus::class)->rodarCiclo();
    expect(painelDe($cliente)->acl_acao)->toBe('ja-aplicada');
});

test('ACL: arquivo que não existe falha só ele; dono do arquivo vira aviso "sem efeito"', function () {
    $cliente = criarClienteSactus(['service_acl' => 'S']);
    $ssh = sshFalso()->servidor('200.1.2.3', 22, 'senha-ssh', ['/etc/sactus.cfg' => cfgSactus()]);
    $ssh->servidores['200.1.2.3|22']['donos']['/etc/sactus.cfg'] = 'suporte';

    app(RoboSactus::class)->rodarCiclo();
    $p = painelDe($cliente);

    expect($p->acl_status)->toBe('N')
        ->and($p->acl_acao)->toBe('arquivo-inexistente')
        ->and($p->acl_erro)->toStartWith('/var/sistema/config.ini: arquivo nao encontrado no servidor | AVISO /etc/sactus.cfg: o arquivo pertence ao usuario suporte');
});

test('ACL não avaliada neste ciclo preserva o resultado anterior', function () {
    $cliente = criarClienteSactus(['service_acl' => 'S']);
    sshFalso()->servidor('200.1.2.3', 22, 'senha-ssh', ['/etc/sactus.cfg' => cfgSactus(), '/var/sistema/config.ini' => 'x']);
    app(RoboSactus::class)->rodarCiclo();

    DB::connection('help')->table('informacao_tecnica_dataserver')->where('fk_c_cliente', $cliente)->update(['service_acl' => 'N']);
    $this->travel(1)->hour();
    app(RoboSactus::class)->rodarCiclo();

    expect(painelDe($cliente)->only(['acl_status', 'acl_acao']))->toBe(['acl_status' => 'S', 'acl_acao' => 'aplicada'])
        ->and(painelDe($cliente)->acl_data->toDateTimeString())->toBe('2026-10-21 10:00:00');
});

test('ERP: rsync, chown e chmod em ordem; a senha do 220 nunca vai para o banco', function () {
    $cliente = criarClienteSactus(['atualizar_erp' => 'S']);
    sshFalso()->servidor('200.1.2.3', 22, 'senha-ssh', ['/etc/sactus.cfg' => cfgSactus()]);

    $ciclo = app(RoboSactus::class)->rodarCiclo();
    $p = painelDe($cliente);

    expect($p->only(['erp_status', 'erp_acao']))->toBe(['erp_status' => 'S', 'erp_acao' => 'executado'])
        ->and(array_column($p->itensErp(), 'acao'))->toBe(['executado', 'executado', 'executado'])
        ->and($p->erp_json)->not->toContain('senha-do-220')->toContain("-p'***'")
        ->and($ciclo->erp_ok)->toBe(1);
});

test('ERP: se o rsync falha, os comandos seguintes não rodam', function () {
    $cliente = criarClienteSactus(['atualizar_erp' => 'S']);
    $ssh = sshFalso()->servidor('200.1.2.3', 22, 'senha-ssh', ['/etc/sactus.cfg' => cfgSactus()])
        ->regra('200.1.2.3', 22, fn (string $c) => str_starts_with($c, 'sshpass ') ? new ResultadoComando(12, '', 'rsync error: error in rsync protocol') : null);

    app(RoboSactus::class)->rodarCiclo();
    $p = painelDe($cliente);

    expect($p->erp_status)->toBe('N')
        ->and(array_column($p->itensErp(), 'acao'))->toBe(['falha', 'nao-executado', 'nao-executado'])
        ->and($p->erp_erro)->toContain('[1] retornou 12 | rsync error')
        ->and(collect($ssh->comandosEm('200.1.2.3', 22))->contains(fn ($c) => str_starts_with($c, 'chmod')))->toBeFalse();
});

test('ERP: sem a senha do 220 no .env a rotina não roda', function () {
    config(['walle.sactus.erp.senha' => '']);
    $cliente = criarClienteSactus(['atualizar_erp' => 'S']);
    $ssh = sshFalso()->servidor('200.1.2.3', 22, 'senha-ssh', ['/etc/sactus.cfg' => cfgSactus()]);

    app(RoboSactus::class)->rodarCiclo();

    expect(painelDe($cliente)->erp_status)->toBe('X')
        ->and(collect($ssh->comandosEm('200.1.2.3', 22))->contains(fn ($c) => str_starts_with($c, 'sshpass')))->toBeFalse();
});

test('ERP pela VPN: se a VPN não conecta, coleta pelo externo mas não atualiza por ele', function () {
    $cliente = criarClienteSactus(['atualizar_erp' => 'S', 'ip_interno_servidor' => '10.8.0.15']);
    $ssh = sshFalso(['10.8.0.15|22'])->servidor('200.1.2.3', 22, 'senha-ssh', ['/etc/sactus.cfg' => cfgSactus()]);

    app(RoboSactus::class)->rodarCiclo();
    $p = painelDe($cliente);

    expect($p->status)->toBe('S')
        ->and($p->only(['erp_status', 'erp_acao']))->toBe(['erp_status' => 'N', 'erp_acao' => 'vpn-inacessivel'])
        ->and(collect($ssh->comandosEm('200.1.2.3', 22))->contains(fn ($c) => str_starts_with($c, 'sshpass')))->toBeFalse();
});

test('edição pendente: backup, grava, relê e a coleta do mesmo ciclo já traz o valor novo', function () {
    $cliente = criarClienteSactus();
    $ssh = sshFalso()->servidor('200.1.2.3', 22, 'senha-ssh', ['/etc/sactus.cfg' => cfgSactus()]);
    $alterar = pedidoDeEdicao($cliente, 'ADICIONAR', 'bloquear_importacao_ibscbs', 'S');
    $remover = pedidoDeEdicao($cliente, 'REMOVER', 'debug');

    $ciclo = app(RoboSactus::class)->rodarCiclo();

    expect($alterar->refresh()->status)->toBe('CONCLUIDO')
        ->and($alterar->mensagem)->toBe('valor atualizado na linha existente | backup em /home/sactus.cfg_20261021_100000_e'.$alterar->id.'.bkp')
        ->and($remover->refresh()->status)->toBe('CONCLUIDO')
        ->and($ssh->arquivo('200.1.2.3', 22, '/etc/sactus.cfg'))->toBe("licenciado=S\njm_database_password=segredo-do-banco\nbloquear_importacao_ibscbs=S\n")
        ->and($ssh->arquivo('200.1.2.3', 22, '/home/sactus.cfg_20261021_100000_e'.$alterar->id.'.bkp'))->toBe(cfgSactus())
        ->and(painelDe($cliente)->flags())->toMatchArray(['bloquear_importacao_ibscbs' => 'S'])->not->toHaveKey('debug')
        ->and($ciclo->cfg_ok)->toBe(2);
});

test('edição que não confere depois de gravar volta o backup e vira ERRO', function () {
    $cliente = criarClienteSactus();
    // O servidor "grava" outra coisa por cima do arquivo
    $ssh = sshFalso()->servidor('200.1.2.3', 22, 'senha-ssh', ['/etc/sactus.cfg' => cfgSactus()])
        ->regra('200.1.2.3', 22, function (string $c, string $chave) use (&$ssh) {
            if (str_starts_with($c, "cat '/tmp/sactus_cfg_edit.new' > ")) {
                $ssh->servidores[$chave]['arquivos']['/etc/sactus.cfg'] = "corrompido=1\n";

                return new ResultadoComando(0);
            }

            return null;
        });
    $pedido = pedidoDeEdicao($cliente, 'ADICIONAR', 'debug', 'S');

    app(RoboSactus::class)->rodarCiclo();

    expect($pedido->refresh()->status)->toBe('ERRO')
        ->and($pedido->mensagem)->toStartWith('o conteudo lido apos a escrita nao confere com o esperado | arquivo restaurado a partir de /home/')
        ->and($ssh->arquivo('200.1.2.3', 22, '/etc/sactus.cfg'))->toBe(cfgSactus());
});

test('edição: leitura do arquivo falhando volta para a fila até a 3ª tentativa', function () {
    $cliente = criarClienteSactus();
    sshFalso()->servidor('200.1.2.3', 22, 'senha-ssh', []);
    $pedido = pedidoDeEdicao($cliente, 'ADICIONAR', 'debug', 'S');

    app(RoboSactus::class)->rodarCiclo();
    expect($pedido->refresh()->only(['status', 'tentativas']))->toBe(['status' => 'PENDENTE', 'tentativas' => 1])
        ->and($pedido->mensagem)->toContain('nao foi possivel ler o /etc/sactus.cfg')->toContain('(tentativa 1/3)');

    app(RoboSactus::class)->rodarCiclo();
    app(RoboSactus::class)->rodarCiclo();
    expect($pedido->refresh()->only(['status', 'tentativas']))->toBe(['status' => 'ERRO', 'tentativas' => 3]);
});

test('pedido preso em PROCESSANDO há mais de 30 min volta para a fila', function () {
    $cliente = criarClienteSactus();
    $preso = pedidoDeEdicao($cliente, 'ADICIONAR', 'debug', 'S', ['status' => 'PROCESSANDO', 'iniciado_em' => now()->subHour(), 'tentativas' => 1]);
    sshFalso()->servidor('200.1.2.3', 22, 'senha-ssh', ['/etc/sactus.cfg' => cfgSactus()]);

    app(RoboSactus::class)->rodarCiclo();

    expect($preso->refresh()->status)->toBe('CONCLUIDO');
});

test('cliente travado por outro robô não é processado de novo', function () {
    $cliente = criarClienteSactus();
    $ssh = sshFalso()->servidor('200.1.2.3', 22, 'senha-ssh', ['/etc/sactus.cfg' => cfgSactus()]);
    Cache::lock('sactus:cliente:'.$cliente, 60)->get();

    $ciclo = app(RoboSactus::class)->rodarCiclo();

    expect($ssh->comandos)->toBe([])
        ->and($ciclo->coletados)->toBe(0)
        ->and(PainelSactusCfg::query()->count())->toBe(0);
});

test('o job só roda com o Sactus ligado e sem pausa', function () {
    criarClienteSactus();
    sshFalso()->servidor('200.1.2.3', 22, 'senha-ssh', ['/etc/sactus.cfg' => cfgSactus()]);
    $robo = app(RoboSactus::class);

    config(['walle.sactus.ativo' => false]);
    (new RodarCicloSactus)->handle($robo);
    expect(CicloSactus::count())->toBe(0);

    config(['walle.sactus.ativo' => true]);
    $robo->pausar(now()->addHour());
    (new RodarCicloSactus)->handle($robo);
    expect(CicloSactus::count())->toBe(0);

    $robo->retomar();
    (new RodarCicloSactus)->handle($robo);
    expect(CicloSactus::count())->toBe(1);
});
