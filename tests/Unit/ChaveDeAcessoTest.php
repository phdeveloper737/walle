<?php

use App\Sefaz\ChaveDeAcesso;
use App\Sefaz\SituacaoNfce;

test('chave válida de GO não tem problema e mostra as partes', function () {
    $chave = ChaveDeAcesso::de(chaveValida(123));

    expect($chave->problema())->toBeNull()
        ->and($chave->uf())->toBe('52')
        ->and($chave->modelo())->toBe('65')
        ->and($chave->tipoEmissao())->toBe('9')
        ->and($chave->referencia())->toBe('2026-10-01')
        ->and($chave->cnpjFormatado())->toBe('12.345.678/0001-90');
});

test('aceita a chave com espaços e prefixo', function () {
    $chave = chaveValida(5);

    expect(ChaveDeAcesso::de('NFe '.chunk_split($chave, 4, ' '))->chave)->toBe($chave);
});

test('mesmas validações do sistema antigo antes de consultar', function () {
    $valida = chaveValida(77);
    $dvErrado = substr($valida, 0, 43).(((int) $valida[43] + 1) % 10);

    expect(ChaveDeAcesso::de('123')->problema())->toBe('Chave deve ter 44 dígitos')
        ->and(ChaveDeAcesso::de(chaveValida(1, uf: '35'))->problema())->toBe('Chave não pertence à UF 52 (cUF 35)')
        ->and(ChaveDeAcesso::de(chaveValida(1, modelo: '59'))->problema())->toBe('Modelo inválido (59)')
        ->and(ChaveDeAcesso::de($dvErrado)->problema())->toBe('Dígito verificador inválido');
});

test('mês inválido na chave não vira referência', function () {
    expect(ChaveDeAcesso::de(chaveValida(1, aamm: '2613'))->referencia())->toBeNull();
});

test('situação a partir do cStat, igual ao consulta.nfce.php', function () {
    expect(SituacaoNfce::doCStat('100'))->toBe(SituacaoNfce::Autorizada)
        ->and(SituacaoNfce::doCStat('100', cancelada: true))->toBe(SituacaoNfce::Cancelada)
        ->and(SituacaoNfce::doCStat('135'))->toBe(SituacaoNfce::Cancelada)
        ->and(SituacaoNfce::doCStat('302'))->toBe(SituacaoNfce::Denegada)
        ->and(SituacaoNfce::doCStat('217'))->toBe(SituacaoNfce::Inexistente)
        ->and(SituacaoNfce::doCStat('656'))->toBe(SituacaoNfce::ConsumoIndevido)
        ->and(SituacaoNfce::doCStat('999'))->toBe(SituacaoNfce::Indefinida);
});
