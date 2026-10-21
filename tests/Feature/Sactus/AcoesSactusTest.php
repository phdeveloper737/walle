<?php

use App\Jobs\ExecutarAcaoSactus;
use App\Models\AcaoSactus;
use App\Models\Auditoria;
use App\Models\Usuario;
use App\Sactus\AcoesSactus;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->usuario = Usuario::find(criarUsuarioHelpDesk());
    $this->actingAs($this->usuario);
    config(['walle.sactus.ativo' => true]);
});

test('pedir registra quem pediu, audita e põe na fila; uma ação aberta por cliente', function () {
    Queue::fake();
    $cliente = criarClienteSactus();
    $acoes = app(AcoesSactus::class);

    $pedido = $acoes->pedir($cliente, 'YUM_UPDATE', $this->usuario, '10.0.0.9');

    expect($pedido->only(['status', 'solicitante', 'ip']))->toBe(['status' => 'PENDENTE', 'solicitante' => 'Maria da Silva', 'ip' => '10.0.0.9'])
        ->and(Auditoria::query()->where('acao', 'sactus.acao.pedir')->where('fk_c_cliente', $cliente)->exists())->toBeTrue();
    Queue::assertPushed(ExecutarAcaoSactus::class, fn ($job) => $job->acaoId === $pedido->id);

    expect(fn () => $acoes->pedir($cliente, 'INSTALAR_ACL', $this->usuario, null))->toThrow(InvalidArgumentException::class, 'Já tem uma ação em andamento');
    expect(fn () => $acoes->pedir($cliente, 'RM_RF', $this->usuario, null))->toThrow(InvalidArgumentException::class, 'Ação desconhecida.');
});

test('executar roda o yum update no servidor e guarda a saída', function () {
    $cliente = criarClienteSactus();
    $ssh = sshFalso()->servidor('200.1.2.3', 22, 'senha-ssh', ['/etc/sactus.cfg' => cfgSactus()]);
    $pedido = AcaoSactus::create(['fk_c_cliente' => $cliente, 'acao' => 'YUM_UPDATE', 'solicitante_id' => $this->usuario->id, 'solicitante' => 'Maria', 'criado_em' => now()]);

    (new ExecutarAcaoSactus($pedido->id))->handle(app(AcoesSactus::class));

    expect($pedido->refresh()->only(['status', 'rc', 'saida', 'alvo', 'erro']))
        ->toBe(['status' => 'CONCLUIDO', 'rc' => 0, 'saida' => 'Complete!', 'alvo' => 'NORMAL root@200.1.2.3:22', 'erro' => null])
        ->and($ssh->comandosEm('200.1.2.3', 22))->toBe(['yum -y update']);
});

test('usuário que não é root usa sudo -n', function () {
    $cliente = criarClienteSactus(['usuario_ssh' => 'suporte']);
    $ssh = sshFalso()->servidor('200.1.2.3', 22, 'senha-ssh');
    $pedido = AcaoSactus::create(['fk_c_cliente' => $cliente, 'acao' => 'INSTALAR_ACL', 'solicitante_id' => 1, 'solicitante' => 'Maria', 'criado_em' => now()]);

    app(AcoesSactus::class)->executar($pedido);

    expect($ssh->comandosEm('200.1.2.3', 22))->toBe(['sudo -n yum install -y acl']);
});

test('servidor fora do ar ou Sactus desligado: a ação termina em ERRO sem encostar no servidor', function () {
    $cliente = criarClienteSactus();
    $ssh = sshFalso(['200.1.2.3|22']);
    $foraDoAr = AcaoSactus::create(['fk_c_cliente' => $cliente, 'acao' => 'YUM_UPDATE', 'solicitante_id' => 1, 'solicitante' => 'Maria', 'criado_em' => now()]);

    app(AcoesSactus::class)->executar($foraDoAr);
    expect($foraDoAr->refresh()->only(['status', 'erro']))->toBe(['status' => 'ERRO', 'erro' => '200.1.2.3:22 não respondeu']);

    config(['walle.sactus.ativo' => false]);
    $desligado = AcaoSactus::create(['fk_c_cliente' => $cliente, 'acao' => 'YUM_UPDATE', 'solicitante_id' => 1, 'solicitante' => 'Maria', 'criado_em' => now()]);
    (new ExecutarAcaoSactus($desligado->id))->handle(app(AcoesSactus::class));

    expect($desligado->refresh()->only(['status', 'erro']))->toBe(['status' => 'ERRO', 'erro' => 'O Sactus do walle está desligado.'])
        ->and($ssh->comandos)->toBe([]);
});
