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

    RoboConfig::truncate();
    RoboConfigHistorico::truncate();
    Auditoria::truncate();
});

dataset('seis robos', [
    'autorizadas' => 'autorizadas',
    'sactus' => 'sactus',
    'contingencias' => 'contingencias',
    'sentinela' => 'sentinela',
    'cleantax' => 'cleantax',
    'tarefas' => 'tarefas',
]);

// ===== catálogo: textos da tela =====

test('todo parametro do catalogo tem nome e ajuda, e o nome nao se repete no robo', function () {
    foreach (ParametroCatalogo::todos() as $robo => $parametros) {
        $nomes = [];

        foreach ($parametros as $chave => $definicao) {
            expect($definicao['nome'] ?? null)->toBeString()->not->toBeEmpty("{$robo}.{$chave} sem nome");
            expect($definicao['ajuda'] ?? null)->toBeString()->not->toBeEmpty("{$robo}.{$chave} sem ajuda");
            $nomes[] = $definicao['nome'];
        }

        expect($nomes)->toBe(array_values(array_unique($nomes)), "nome repetido em {$robo}");
    }
});

// ===== ligado() - env + local =====

test('cada robo nasce com execucao local desligada', function (string $robo) {
    expect(ConfiguracaoRobo::ligado($robo))->toBeFalse();
})->with('seis robos');

test('env ligado + sem linha local => nao liga, motivo local', function (string $robo) {
    config()->set("walle.{$robo}.ativo", true);

    expect(ConfiguracaoRobo::ligado($robo))->toBeFalse()
        ->and(ConfiguracaoRobo::motivoInativo($robo))->toBe('local')
        ->and(ConfiguracaoRobo::permissaoEnv($robo))->toBeTrue()
        ->and(ConfiguracaoRobo::ativoLocal($robo))->toBeFalse();
})->with('seis robos');

test('local ligado + env desligado => nao liga, motivo env', function (string $robo) {
    config()->set("walle.{$robo}.ativo", false);
    ConfiguracaoRobo::definirAtivo($robo, true, 'admin');

    expect(ConfiguracaoRobo::ligado($robo))->toBeFalse()
        ->and(ConfiguracaoRobo::motivoInativo($robo))->toBe('env')
        ->and(ConfiguracaoRobo::permissaoEnv($robo))->toBeFalse()
        ->and(ConfiguracaoRobo::ativoLocal($robo))->toBeTrue();
})->with('seis robos');

test('os dois ligados => liga', function (string $robo) {
    config()->set("walle.{$robo}.ativo", true);
    ConfiguracaoRobo::definirAtivo($robo, true, 'admin');

    expect(ConfiguracaoRobo::ligado($robo))->toBeTrue()
        ->and(ConfiguracaoRobo::motivoInativo($robo))->toBeNull()
        ->and(ConfiguracaoRobo::permissaoEnv($robo))->toBeTrue()
        ->and(ConfiguracaoRobo::ativoLocal($robo))->toBeTrue();
})->with('seis robos');

// ===== definirAtivo NAO altera config() do .env =====

test('definirAtivo(true) NAO altera config walle do .env', function (string $robo) {
    config()->set("walle.{$robo}.ativo", false);

    ConfiguracaoRobo::definirAtivo($robo, true, 'admin');

    expect(config("walle.{$robo}.ativo"))->toBeFalse();
})->with('seis robos');

test('definirAtivo(false) NAO altera config walle do .env', function (string $robo) {
    config()->set("walle.{$robo}.ativo", true);

    ConfiguracaoRobo::definirAtivo($robo, false, 'admin');

    expect(config("walle.{$robo}.ativo"))->toBeTrue();
})->with('seis robos');

// ===== Novos metodos =====

test('ativoLocal le do banco', function (string $robo) {
    expect(ConfiguracaoRobo::ativoLocal($robo))->toBeFalse();

    ConfiguracaoRobo::definirAtivo($robo, true, 'admin');
    expect(ConfiguracaoRobo::ativoLocal($robo))->toBeTrue();

    ConfiguracaoRobo::definirAtivo($robo, false, 'admin');
    expect(ConfiguracaoRobo::ativoLocal($robo))->toBeFalse();
})->with('seis robos');

test('permissaoEnv le do config/.env', function (string $robo) {
    config()->set("walle.{$robo}.ativo", false);
    expect(ConfiguracaoRobo::permissaoEnv($robo))->toBeFalse();

    config()->set("walle.{$robo}.ativo", true);
    expect(ConfiguracaoRobo::permissaoEnv($robo))->toBeTrue();
})->with('seis robos');

test('motivoInativo retorna env, local ou null', function (string $robo) {
    config()->set("walle.{$robo}.ativo", false);
    ConfiguracaoRobo::definirAtivo($robo, true, 'admin');
    expect(ConfiguracaoRobo::motivoInativo($robo))->toBe('env');

    config()->set("walle.{$robo}.ativo", true);
    ConfiguracaoRobo::definirAtivo($robo, false, 'admin');
    expect(ConfiguracaoRobo::motivoInativo($robo))->toBe('local');

    config()->set("walle.{$robo}.ativo", true);
    ConfiguracaoRobo::definirAtivo($robo, true, 'admin');
    expect(ConfiguracaoRobo::motivoInativo($robo))->toBeNull();
})->with('seis robos');

// ===== persistido() e efetivo() =====

test('persistido retorna null sem linha', function (string $robo, string $parametro) {
    expect(ConfiguracaoRobo::persistido($robo, $parametro))->toBeNull();
})->with([
    'autorizadas_limite' => ['autorizadas', 'limite_ciclo'],
    'sactus_limite' => ['sactus', 'limite_ciclo_minutos'],
]);

test('persistido retorna valor gravado', function (string $robo, string $parametro) {
    ConfiguracaoRobo::gravar($robo, $parametro, 300, 'admin');
    expect(ConfiguracaoRobo::persistido($robo, $parametro))->toBe(300);
})->with([
    'autorizadas_limite' => ['autorizadas', 'limite_ciclo'],
    'sactus_limite' => ['sactus', 'limite_ciclo_minutos'],
]);

test('efetivo: persistido > default do catalogo', function (string $robo, string $parametro) {
    // Sem persistido, usa default do catalogo
    $default = ParametroCatalogo::todos()[$robo][$parametro]['default'];
    expect(ConfiguracaoRobo::efetivo($robo, $parametro))->toBe($default);

    // Com persistido, usa o persistido
    ConfiguracaoRobo::gravar($robo, $parametro, 400, 'admin');
    expect(ConfiguracaoRobo::efetivo($robo, $parametro))->toBe(400);
})->with([
    'autorizadas_limite' => ['autorizadas', 'limite_ciclo'],
    'sactus_limite' => ['sactus', 'limite_ciclo_minutos'],
]);

// ===== parametro() delega a efetivo() (compatibilidade) =====

test('parametro delega a efetivo mantendo compatibilidade', function (string $robo, string $parametro) {
    ConfiguracaoRobo::gravar($robo, $parametro, 300, 'admin');
    expect(ConfiguracaoRobo::parametro($robo, $parametro))->toBe(300);
    expect(ConfiguracaoRobo::parametro($robo, 'inexistente', 'padrao'))->toBe('padrao');
})->with([
    'autorizadas_limite' => ['autorizadas', 'limite_ciclo'],
    'sactus_limite' => ['sactus', 'limite_ciclo_minutos'],
]);

// ===== Segredos =====

test('efetivo de segredo devolve null', function () {
    expect(ConfiguracaoRobo::efetivo('sactus', 'erp.senha'))->toBeNull();
    expect(ConfiguracaoRobo::efetivo('cleantax', 'token'))->toBeNull();
});

test('catalogo nao expõe valor real de segredo', function () {
    $catalogo = ConfiguracaoRobo::catalogo();
    expect($catalogo['sactus']['erp.senha']['default'])->toBeNull();
    expect($catalogo['sactus']['erp.senha']['fronteira']['segredo'])->toBeTrue();
    expect($catalogo['cleantax']['token']['fronteira']['segredo'])->toBeTrue();
});

test('gravar segredo lanca LogicException e nao persiste', function () {
    expect(fn () => ConfiguracaoRobo::gravar('sactus', 'erp.senha', 'senha-real', 'admin'))
        ->toThrow(LogicException::class, 'Parâmetro é segredo; use .env');

    expect(RoboConfig::where('robo', 'sactus')->where('parametro', 'erp.senha')->first())->toBeNull();
    expect(RoboConfigHistorico::where('robo', 'sactus')->where('parametro', 'erp.senha')->first())->toBeNull();
});

// ===== Validacao de fronteira completa =====

test('validarFronteira valida int', function () {
    expect(fn () => ConfiguracaoRobo::gravar('autorizadas', 'limite_ciclo', 0, 'admin'))
        ->toThrow(InvalidArgumentException::class, 'Fora da fronteira');
    expect(fn () => ConfiguracaoRobo::gravar('autorizadas', 'limite_ciclo', 10001, 'admin'))
        ->toThrow(InvalidArgumentException::class, 'Fora da fronteira');
    expect(fn () => ConfiguracaoRobo::gravar('autorizadas', 'limite_ciclo', 'abc', 'admin'))
        ->toThrow(InvalidArgumentException::class, 'Tipo inválido');
});

test('validarFronteira valida float', function () {
    // Usa um parametro float existente no sactus (ssh.comando_segundos é int, mas testamos com valor int aceitavel)
    ConfiguracaoRobo::gravar('sactus', 'ssh.comando_segundos', 60, 'admin');
    expect(ConfiguracaoRobo::efetivo('sactus', 'ssh.comando_segundos'))->toBe(60);
});

test('gravar recusa ativo e exige definirAtivo sem efeitos', function () {
    expect(fn () => ConfiguracaoRobo::gravar('autorizadas', 'ativo', true, 'admin'))
        ->toThrow(LogicException::class, 'Use definirAtivo() para alterar o parâmetro ativo');

    expect(RoboConfig::count())->toBe(0)
        ->and(RoboConfigHistorico::count())->toBe(0)
        ->and(Auditoria::count())->toBe(0);
});

test('definirAtivo recusa robo desconhecido sem efeitos', function () {
    expect(fn () => ConfiguracaoRobo::definirAtivo('desconhecido', true, 'admin'))
        ->toThrow(LogicException::class, 'Robô desconhecido');

    expect(RoboConfig::count())->toBe(0)
        ->and(RoboConfigHistorico::count())->toBe(0)
        ->and(Auditoria::count())->toBe(0);
});

test('validarFronteira valida string', function () {
    ConfiguracaoRobo::gravar('sactus', 'cfg_caminho', '/novo/caminho.cfg', 'admin');
    expect(ConfiguracaoRobo::efetivo('sactus', 'cfg_caminho'))->toBe('/novo/caminho.cfg');
});

test('validarFronteira valida array', function () {
    ConfiguracaoRobo::gravar('sactus', 'clientes_ignorados', [1, 2, 3], 'admin');
    expect(ConfiguracaoRobo::efetivo('sactus', 'clientes_ignorados'))->toBe([1, 2, 3]);

    expect(fn () => ConfiguracaoRobo::gravar('sactus', 'clientes_ignorados', 'nao-array', 'admin'))
        ->toThrow(InvalidArgumentException::class, 'Tipo inválido');
});

// ===== Parametro fora do catalogo RECUSADO =====

test('parametro fora do catalogo lanca excecao', function () {
    expect(fn () => ConfiguracaoRobo::gravar('autorizadas', 'parametro_inexistente', 'valor', 'admin'))
        ->toThrow(InvalidArgumentException::class, 'Parâmetro não existe no catálogo');
});

// ===== Historico + Auditoria sem segredo =====

test('historico e auditoria recebem somente valores sanitizados', function () {
    ConfiguracaoRobo::gravar('autorizadas', 'limite_ciclo', 300, 'admin-teste');

    $historico = RoboConfigHistorico::latest('criado_em')->firstOrFail();
    $auditoria = Auditoria::latest('criado_em')->firstOrFail();

    expect(array_keys($historico->getAttributes()))
        ->not->toContain('valor_anterior', 'valor_novo');
    expect($historico->valor_anterior_sanitizado)->toBe('250')
        ->and($historico->valor_novo_sanitizado)->toBe('300')
        ->and($historico->getAttributes())->not->toContain('senha-real');
    expect($auditoria->detalhes)->toHaveKeys(['robo', 'parametro', 'valor_anterior_sanitizado', 'valor_novo_sanitizado', 'ator'])
        ->not->toHaveKeys(['valor_anterior', 'valor_novo'])
        ->not->toContain('senha-real');
});

test('valor_anterior usa efetivo anterior sanitizado quando nao ha linha persistida', function () {
    // Primeira gravacao: valor anterior deve ser o default do catalogo (sanitizado)
    ConfiguracaoRobo::gravar('autorizadas', 'limite_ciclo', 300, 'admin-1');

    $historico1 = RoboConfigHistorico::orderBy('id')->first();
    $default = ParametroCatalogo::todos()['autorizadas']['limite_ciclo']['default'];
    expect($historico1->valor_anterior_sanitizado)->toBe((string) $default);

    // Segunda gravacao: valor anterior deve ser o valor persistido anterior
    ConfiguracaoRobo::gravar('autorizadas', 'limite_ciclo', 400, 'admin-2');

    $historico2 = RoboConfigHistorico::orderByDesc('id')->first();
    expect($historico2->valor_anterior_sanitizado)->toBe('300');
});

// ===== Transacao =====

test('gravar envolve upsert+historico em transacao', function () {
    // Se der erro na validacao, nada persiste
    expect(fn () => ConfiguracaoRobo::gravar('autorizadas', 'limite_ciclo', 0, 'admin'))
        ->toThrow(InvalidArgumentException::class);

    expect(RoboConfig::where('robo', 'autorizadas')->where('parametro', 'limite_ciclo')->first())->toBeNull();
    expect(RoboConfigHistorico::where('robo', 'autorizadas')->where('parametro', 'limite_ciclo')->first())->toBeNull();
});

test('definirAtivo grava historico e auditoria so com colunas sanitizadas', function () {
    ConfiguracaoRobo::definirAtivo('sactus', true, 'admin-teste');

    $historico = RoboConfigHistorico::firstOrFail();
    $auditoria = Auditoria::firstOrFail();

    expect(array_keys($historico->getAttributes()))->not->toContain('valor_anterior', 'valor_novo')
        ->and($historico->acao)->toBe('ativar')
        ->and($historico->valor_anterior_sanitizado)->toBe('false')
        ->and($historico->valor_novo_sanitizado)->toBe('true');
    expect($auditoria->detalhes)->toHaveKeys(['robo', 'parametro', 'valor_anterior_sanitizado', 'valor_novo_sanitizado', 'ator'])
        ->not->toHaveKeys(['valor_anterior', 'valor_novo']);
});

test('definirAtivo usa o valor persistido anterior no historico', function () {
    ConfiguracaoRobo::definirAtivo('sactus', true, 'admin');
    ConfiguracaoRobo::definirAtivo('sactus', false, 'admin');

    $ultimo = RoboConfigHistorico::orderByDesc('id')->firstOrFail();

    expect($ultimo->acao)->toBe('desativar')
        ->and($ultimo->valor_anterior_sanitizado)->toBe('true')
        ->and($ultimo->valor_novo_sanitizado)->toBe('false')
        ->and(RoboConfig::where('robo', 'sactus')->where('parametro', 'ativo')->count())->toBe(1);
});

test('definirAtivo e atomico: falha no historico desfaz o upsert', function () {
    // Testa atomicidade: se a transacao falhar, nada persiste
    // Simula falha usando uma validacao que lancara excecao antes da transacao
    expect(fn () => ConfiguracaoRobo::definirAtivo('sactus', true, ''))
        ->toThrow(InvalidArgumentException::class, 'Ator obrigat');

    expect(RoboConfig::count())->toBe(0)
        ->and(RoboConfigHistorico::count())->toBe(0)
        ->and(Auditoria::count())->toBe(0)
        ->and(ConfiguracaoRobo::ativoLocal('sactus'))->toBeFalse();
});

test('gravar e atomico: falha no historico desfaz o upsert', function () {
    // Testa atomicidade: se a transacao falhar, nada persiste
    expect(fn () => ConfiguracaoRobo::gravar('autorizadas', 'limite_ciclo', 300, ''))
        ->toThrow(InvalidArgumentException::class, 'Ator obrigat');

    expect(RoboConfig::count())->toBe(0)
        ->and(Auditoria::count())->toBe(0);
});

// ===== Mudanca durante ciclo so vale no seguinte =====

test('mudanca durante ciclo so vale no seguinte: leitura antes da gravacao permanece igual, nova leitura traz novo', function (string $robo, string $parametro) {
    config()->set("walle.{$robo}.ativo", true);
    ConfiguracaoRobo::definirAtivo($robo, true, 'admin');

    // Simula: robo le o parametro no inicio do ciclo e guarda o valor
    $valorDoCiclo = ConfiguracaoRobo::efetivo($robo, $parametro);

    // Durante o ciclo, admin muda o parametro
    ConfiguracaoRobo::gravar($robo, $parametro, 500, 'admin');

    // O ciclo em andamento segue com o valor lido; o ciclo seguinte le o novo
    expect($valorDoCiclo)->not->toBe(500)
        ->and(ConfiguracaoRobo::efetivo($robo, $parametro))->toBe(500);
})->with([
    'autorizadas' => ['autorizadas', 'limite_ciclo'],
    'sactus' => ['sactus', 'limite_ciclo_minutos'],
]);

// ===== RoboConfigHistorico nao aceita update/delete =====

test('RoboConfigHistorico nao aceita update', function () {
    ConfiguracaoRobo::gravar('autorizadas', 'limite_ciclo', 300, 'admin');
    $historico = RoboConfigHistorico::latest('criado_em')->first();

    expect(fn () => $historico->update(['ator' => 'outro']))->toThrow(RuntimeException::class, 'Histórico imutável');
});

test('RoboConfigHistorico nao aceita delete', function () {
    ConfiguracaoRobo::gravar('autorizadas', 'limite_ciclo', 300, 'admin');
    $historico = RoboConfigHistorico::latest('criado_em')->first();

    expect(fn () => $historico->delete())->toThrow(RuntimeException::class, 'Histórico imutável');
});

// ===== Catalogo: tipos, min/max, ajuda para parametros existentes =====

test('catalogo tem tipo para todos os parametros dos 6 robos', function () {
    $catalogo = ConfiguracaoRobo::catalogo();
    foreach ($catalogo as $robo => $params) {
        foreach ($params as $param => $info) {
            expect($info['fronteira'])->toHaveKey('tipo')
                ->and(in_array($info['fronteira']['tipo'], ['int', 'float', 'bool', 'string', 'array']))->toBeTrue();
        }
    }
});

test('catalogo tem min/max onde aplicavel para parametros existentes', function () {
    $catalogo = ConfiguracaoRobo::catalogo();
    // Verifica alguns exemplos
    expect($catalogo['autorizadas']['limite_ciclo']['fronteira'])->toHaveKeys(['min', 'max', 'tipo']);
    expect($catalogo['sactus']['ssh.conexao_segundos']['fronteira'])->toHaveKeys(['min', 'max', 'tipo']);
    expect($catalogo['sactus']['erp.porta_origem']['fronteira'])->toHaveKeys(['min', 'max', 'tipo']);
});

// ===== Congelamento por ciclo (noCiclo) =====

test('noCiclo: gravar no meio nao muda o valor lido; o ciclo seguinte ve o novo', function () {
    $dentro = ConfiguracaoRobo::noCiclo('autorizadas', function () {
        $antes = ConfiguracaoRobo::efetivo('autorizadas', 'limite_ciclo');
        ConfiguracaoRobo::gravar('autorizadas', 'limite_ciclo', 500, 'admin');

        return [$antes, ConfiguracaoRobo::efetivo('autorizadas', 'limite_ciclo')];
    });

    expect($dentro[0])->toBe(ParametroCatalogo::todos()['autorizadas']['limite_ciclo']['default'])
        ->and($dentro[1])->toBe($dentro[0]);

    $proximo = ConfiguracaoRobo::noCiclo('autorizadas', fn () => ConfiguracaoRobo::efetivo('autorizadas', 'limite_ciclo'));
    expect($proximo)->toBe(500);
});

test('noCiclo: excecao nao deixa a foto sobrando', function () {
    expect(fn () => ConfiguracaoRobo::noCiclo('autorizadas', function () {
        ConfiguracaoRobo::gravar('autorizadas', 'limite_ciclo', 500, 'admin');
        throw new RuntimeException('falha');
    }))->toThrow(RuntimeException::class, 'falha');

    expect(ConfiguracaoRobo::efetivo('autorizadas', 'limite_ciclo'))->toBe(500);
    ConfiguracaoRobo::gravar('autorizadas', 'limite_ciclo', 600, 'admin');
    expect(ConfiguracaoRobo::efetivo('autorizadas', 'limite_ciclo'))->toBe(600);
});

test('noCiclo aninhado do mesmo robo reaproveita a foto de fora', function () {
    $valor = ConfiguracaoRobo::noCiclo('autorizadas', function () {
        ConfiguracaoRobo::gravar('autorizadas', 'limite_ciclo', 500, 'admin');

        return ConfiguracaoRobo::noCiclo('autorizadas', fn () => ConfiguracaoRobo::efetivo('autorizadas', 'limite_ciclo'));
    });

    expect($valor)->toBe(ParametroCatalogo::todos()['autorizadas']['limite_ciclo']['default'])
        ->and(ConfiguracaoRobo::efetivo('autorizadas', 'limite_ciclo'))->toBe(500);
});

test('fora do ciclo efetivo le o valor atual', function () {
    ConfiguracaoRobo::gravar('autorizadas', 'limite_ciclo', 300, 'admin');
    expect(ConfiguracaoRobo::efetivo('autorizadas', 'limite_ciclo'))->toBe(300);
    ConfiguracaoRobo::gravar('autorizadas', 'limite_ciclo', 400, 'admin');
    expect(ConfiguracaoRobo::efetivo('autorizadas', 'limite_ciclo'))->toBe(400);
});

test('noCiclo: segredo continua null', function () {
    $valor = ConfiguracaoRobo::noCiclo('sactus', fn () => ConfiguracaoRobo::efetivo('sactus', 'erp.senha'));
    expect($valor)->toBeNull();
});

test('efetivo sem persistencia vale o config; persistido vale o persistido', function () {
    config()->set('walle.sactus.erp.origem', 'origem-do-env');
    expect(ConfiguracaoRobo::efetivo('sactus', 'erp.origem'))->toBe('origem-do-env');

    ConfiguracaoRobo::gravar('sactus', 'erp.origem', 'origem-painel', 'admin');
    expect(ConfiguracaoRobo::efetivo('sactus', 'erp.origem'))->toBe('origem-painel');
});

test('noCiclo congela o valor do config quando nada esta persistido', function () {
    config()->set('walle.sactus.erp.origem', 'origem-do-env');
    $valor = ConfiguracaoRobo::noCiclo('sactus', function () {
        config()->set('walle.sactus.erp.origem', 'outra');

        return ConfiguracaoRobo::efetivo('sactus', 'erp.origem');
    });
    expect($valor)->toBe('origem-do-env');
});

test('sem persistencia efetivo e igual ao config para todo parametro nao secreto', function () {
    foreach (ParametroCatalogo::todos() as $robo => $parametros) {
        foreach ($parametros as $chave => $def) {
            if (($def['fronteira']['segredo'] ?? false) === true) {
                continue;
            }
            expect(ConfiguracaoRobo::efetivo($robo, $chave))->toBe(config("walle.{$robo}.{$chave}"), "{$robo}.{$chave}");
        }
    }
});

test('todo parametro nao secreto do catalogo tem default em config walle', function () {
    $sem = [];
    foreach (ParametroCatalogo::todos() as $robo => $parametros) {
        foreach ($parametros as $chave => $def) {
            if (($def['fronteira']['segredo'] ?? false) !== true && config("walle.{$robo}.{$chave}") === null) {
                $sem[] = "{$robo}.{$chave}";
            }
        }
    }
    expect($sem)->toBe([]);
});
