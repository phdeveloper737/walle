<?php

use App\Models\Auditoria;
use App\Models\RoboConfig;
use App\Models\RoboConfigHistorico;
use App\Support\ConfiguracaoRobo;
use App\Support\ParametroCatalogo;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config()->set('walle.autorizadas.ativo', false);
    config()->set('walle.sactus.ativo', false);
    config()->set('walle.contingencias.ativo', false);
    config()->set('walle.sentinela.ativo', false);
    config()->set('walle.cleantax.ativo', false);
    config()->set('walle.tarefas.ativo', false);
});

test('ligado retorna false com default do config', function () {
    expect(ConfiguracaoRobo::ligado('autorizadas'))->toBeFalse();
    expect(ConfiguracaoRobo::ligado('sactus'))->toBeFalse();
    expect(ConfiguracaoRobo::ligado('contingencias'))->toBeFalse();
    expect(ConfiguracaoRobo::ligado('sentinela'))->toBeFalse();
    expect(ConfiguracaoRobo::ligado('cleantax'))->toBeFalse();
    expect(ConfiguracaoRobo::ligado('tarefas'))->toBeFalse();
});

test('parametro le defaults do config', function () {
    expect(ConfiguracaoRobo::parametro('autorizadas', 'limite_ciclo'))->toBe(250);
    expect(ConfiguracaoRobo::parametro('sactus', 'limite_ciclo_minutos'))->toBe(55);
    expect(ConfiguracaoRobo::parametro('sactus', 'ssh.conexao_segundos'))->toBe(10);
    expect(ConfiguracaoRobo::parametro('cleantax', 'token'))->toBeNull();
});

test('gravar com ator vazio lanca excecao', function () {
    ConfiguracaoRobo::gravar('autorizadas', 'limite_ciclo', 300, '');
})->throws(InvalidArgumentException::class, 'Ator obrigatório');

test('gravar com segredo lanca LogicException', function () {
    ConfiguracaoRobo::gravar('sactus', 'erp.senha', 'minha-senha', 'admin');
})->throws(LogicException::class, 'Parâmetro é segredo; use .env');

test('gravar valido persiste em RoboConfig + RoboConfigHistorico + Auditoria', function () {
    $configAnterior = RoboConfig::where('robo', 'autorizadas')->where('parametro', 'limite_ciclo')->first();
    $historicoAnterior = RoboConfigHistorico::count();
    $auditoriaAnterior = Auditoria::count();

    ConfiguracaoRobo::gravar('autorizadas', 'limite_ciclo', 300, 'admin-teste');

    $config = RoboConfig::where('robo', 'autorizadas')->where('parametro', 'limite_ciclo')->first();
    expect($config)->not->toBeNull()
        ->and($config->valor)->toBe('300')
        ->and($config->valor_sanitizado)->toBe('300');

    $historico = RoboConfigHistorico::latest('criado_em')->first();
    expect($historico)->not->toBeNull()
        ->and($historico->robo)->toBe('autorizadas')
        ->and($historico->parametro)->toBe('limite_ciclo')
        ->and($historico->acao)->toBe('alterar')
        ->and($historico->ator)->toBe('admin-teste')
        ->and($historico->valor_novo_sanitizado)->toBe('300');

    $auditoria = Auditoria::latest('criado_em')->first();
    expect($auditoria)->not->toBeNull()
        ->and($auditoria->acao)->toBe('robo.config.alterar')
        ->and($auditoria->detalhes['robo'])->toBe('autorizadas')
        ->and($auditoria->detalhes['parametro'])->toBe('limite_ciclo')
        ->and($auditoria->detalhes['valor_novo_sanitizado'])->toBe('300');
});

test('definirAtivo grava historico com acao ativar/desativar', function () {
    ConfiguracaoRobo::definirAtivo('autorizadas', true, 'admin-teste');

    $historico = RoboConfigHistorico::orderBy('id', 'desc')->first();
    expect($historico)->not->toBeNull()
        ->and($historico->robo)->toBe('autorizadas')
        ->and($historico->parametro)->toBe('ativo')
        ->and($historico->acao)->toBe('ativar');

    ConfiguracaoRobo::definirAtivo('autorizadas', false, 'admin-teste');

    $historico = RoboConfigHistorico::orderBy('id', 'desc')->first();
    expect($historico)->not->toBeNull()
        ->and($historico->acao)->toBe('desativar');
});

test('catalogo retorna array completo com requer_decisao_usuario onde aplicavel', function () {
    $catalogo = ConfiguracaoRobo::catalogo();

    expect($catalogo)->toHaveKeys(['autorizadas', 'sactus', 'contingencias', 'sentinela', 'cleantax', 'tarefas']);

    // Verifica estrutura basica de cada robo
    foreach ($catalogo as $robo => $params) {
        expect($params)->toBeArray();
        foreach ($params as $param => $info) {
            expect($info)->toHaveKeys(['default', 'unidade', 'fronteira']);
        }
    }

    // Verifica alguns parametros conhecidos
    expect($catalogo['autorizadas']['limite_ciclo']['default'])->toBe(250);
    expect($catalogo['autorizadas']['limite_ciclo']['unidade'])->toBe('chaves');
    expect($catalogo['autorizadas']['limite_ciclo']['fronteira']['min'])->toBe(1);

    expect($catalogo['sactus']['erp.senha']['fronteira']['segredo'])->toBeTrue();
    expect($catalogo['sactus']['erp.senha']['requer_decisao_usuario'])->toBeTrue();

    expect($catalogo['cleantax']['token']['fronteira']['segredo'])->toBeTrue();
});

test('ParametroCatalogo::todos retorna mesmo array do catalogo', function () {
    $catalogo = ConfiguracaoRobo::catalogo();
    $todos = ParametroCatalogo::todos();

    expect($todos)->toBe($catalogo);
});

test('gravar segredo nao persiste valor real no historico', function () {
    expect(fn () => ConfiguracaoRobo::gravar('sactus', 'erp.senha', 'senha-real', 'admin'))
        ->toThrow(LogicException::class);

    $historico = RoboConfigHistorico::where('robo', 'sactus')->where('parametro', 'erp.senha')->latest('criado_em')->first();
    expect($historico)->toBeNull();

    $config = RoboConfig::where('robo', 'sactus')->where('parametro', 'erp.senha')->first();
    expect($config)->toBeNull();
});

test('gravar atualiza valor existente', function () {
    // Primeiro grava
    ConfiguracaoRobo::gravar('autorizadas', 'limite_ciclo', 300, 'admin-1');

    // Depois atualiza
    ConfiguracaoRobo::gravar('autorizadas', 'limite_ciclo', 400, 'admin-2');

    $config = RoboConfig::where('robo', 'autorizadas')->where('parametro', 'limite_ciclo')->first();
    expect($config->valor)->toBe('400');

    $historicos = RoboConfigHistorico::where('robo', 'autorizadas')->where('parametro', 'limite_ciclo')->orderBy('criado_em')->get();
    expect($historicos)->toHaveCount(2);
    expect($historicos[0]->valor_novo_sanitizado)->toBe('300');
    expect($historicos[1]->valor_anterior_sanitizado)->toBe('300');
    expect($historicos[1]->valor_novo_sanitizado)->toBe('400');
});

test('gravar valida fronteira min/max', function () {
    // limite_ciclo tem min=1, max=10000
    expect(fn () => ConfiguracaoRobo::gravar('autorizadas', 'limite_ciclo', 0, 'admin'))
        ->toThrow(InvalidArgumentException::class, 'Fora da fronteira');

    expect(fn () => ConfiguracaoRobo::gravar('autorizadas', 'limite_ciclo', 10001, 'admin'))
        ->toThrow(InvalidArgumentException::class, 'Fora da fronteira');

    // Valor valido
    ConfiguracaoRobo::gravar('autorizadas', 'limite_ciclo', 500, 'admin');
    $config = RoboConfig::where('robo', 'autorizadas')->where('parametro', 'limite_ciclo')->first();
    expect($config->valor)->toBe('500');
});

test('gravar valida tipo', function () {
    // limite_ciclo e int
    expect(fn () => ConfiguracaoRobo::gravar('autorizadas', 'limite_ciclo', 'abc', 'admin'))
        ->toThrow(InvalidArgumentException::class, 'Tipo inválido');

    // pausa_entre_chaves e int
    expect(fn () => ConfiguracaoRobo::gravar('autorizadas', 'pausa_entre_chaves', 'xyz', 'admin'))
        ->toThrow(InvalidArgumentException::class, 'Tipo inválido');
});

test('definirAtivo atualiza config walle em tempo de execucao', function () {
    expect(config('walle.autorizadas.ativo'))->toBeFalse();

    ConfiguracaoRobo::definirAtivo('autorizadas', true, 'admin');
    expect(config('walle.autorizadas.ativo'))->toBeTrue();

    ConfiguracaoRobo::definirAtivo('autorizadas', false, 'admin');
    expect(config('walle.autorizadas.ativo'))->toBeFalse();
});
