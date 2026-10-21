<?php

use App\Support\Texto;

test('mantém UTF-8, converte latin1 e limpa espaços', function () {
    expect(Texto::utf8('Rejeição'))->toBe('Rejeição')
        ->and(Texto::utf8(mb_convert_encoding('Rejeição: CFOP não permitido', 'ISO-8859-1', 'UTF-8')))->toBe('Rejeição: CFOP não permitido')
        ->and(Texto::utf8("  MERCADO TESTE\t"))->toBe('MERCADO TESTE')
        ->and(Texto::utf8(null))->toBe('')
        ->and(Texto::utf8(18.5))->toBe('18.5');
});
