<?php

use App\Sefaz\ConsultaSituacao;
use App\Sefaz\SituacaoNfce;
use Tests\Support\SessaoSefazFalsa;

test('lê autorizada com protocolo', function () {
    $chave = chaveValida(1);
    $resultado = ConsultaSituacao::interpretar($chave, SessaoSefazFalsa::resposta($chave, '100'));

    expect($resultado->situacao)->toBe(SituacaoNfce::Autorizada)
        ->and($resultado->cStat)->toBe('100')
        ->and($resultado->motivo)->toBe('Autorizado o uso da NF-e')
        ->and($resultado->protocolo)->toBe('152260000000001')
        ->and($resultado->recebidaEm)->toBe('2026-10-20T10:00:00-03:00');
});

test('evento 110111 marca como cancelada mesmo com cStat 100', function () {
    $chave = chaveValida(2);

    expect(ConsultaSituacao::interpretar($chave, SessaoSefazFalsa::resposta($chave, '100', eventoCancelamento: true))->situacao)
        ->toBe(SituacaoNfce::Cancelada);
});

test('resposta ilegível vira erro', function () {
    expect(ConsultaSituacao::interpretar(chaveValida(3), 'isso não é xml')->situacao)->toBe(SituacaoNfce::Erro)
        ->and(ConsultaSituacao::interpretar(chaveValida(3), '<a><b/></a>')->motivo)->toBe('retConsSitNFe ausente na resposta');
});

test('chave inválida nem chega na Sefaz', function () {
    $abriu = false;
    $consulta = new ConsultaSituacao(function () use (&$abriu) {
        $abriu = true;

        return new SessaoSefazFalsa;
    });

    $resultados = $consulta->consultar(['123', chaveValida(1, uf: '35')], pausaMs: 0);

    expect($abriu)->toBeFalse()
        ->and(array_map(fn ($r) => $r->situacao, $resultados))->toBe([SituacaoNfce::Erro, SituacaoNfce::Erro]);
});

test('para na hora quando a Sefaz acusa consumo indevido (656)', function () {
    [$a, $b, $c] = [chaveValida(10), chaveValida(11), chaveValida(12)];
    $sessao = new SessaoSefazFalsa([$a => '100', $b => '656', $c => '100']);

    $resultados = (new ConsultaSituacao(fn () => $sessao))->consultar([$a, $b, $c], pausaMs: 0);

    expect($sessao->consultadas)->toBe([$a, $b])
        ->and($resultados[2]->situacao)->toBe(SituacaoNfce::Erro)
        ->and($resultados[2]->motivo)->toContain('consumo indevido')
        ->and($sessao->fechada)->toBeTrue();
});
