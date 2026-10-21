<?php

use App\Sactus\EdicaoNoTexto;

test('incluir flag nova vai para o fim, no mesmo fim de linha do arquivo', function () {
    $r = EdicaoNoTexto::aplicar("a=1\r\nb=2", 'ADICIONAR', 'nova', 'S');

    expect($r)->toMatchArray(['ok' => true, 'mudou' => true, 'novo' => "a=1\r\nb=2\r\nnova=S\r\n", 'msg' => 'flag inexistente, incluida no fim do arquivo']);
});

test('alterar troca só o valor e preserva espaços, separador e o resto do arquivo', function () {
    $cfg = "# topo\n  Debug = N\nporta: 3306\nfim=1\n";

    expect(EdicaoNoTexto::aplicar($cfg, 'ADICIONAR', 'debug', 'S'))->toMatchArray([
        'ok' => true,
        'mudou' => true,
        'novo' => "# topo\n  Debug = S\nporta: 3306\nfim=1\n",
        'msg' => 'valor atualizado na linha existente | o arquivo grava a flag como "Debug"',
    ])->and(EdicaoNoTexto::aplicar($cfg, 'ADICIONAR', 'porta', '3307')['novo'])->toBe("# topo\n  Debug = N\nporta: 3307\nfim=1\n");
});

test('linha comentada não conta e flag repetida só muda a primeira (com aviso)', function () {
    expect(EdicaoNoTexto::aplicar("#x=1\n", 'ADICIONAR', 'x', '2')['novo'])->toBe("#x=1\nx=2\n");

    $r = EdicaoNoTexto::aplicar("x=1\nx=1\n", 'ADICIONAR', 'x', '2');
    expect($r['novo'])->toBe("x=2\nx=1\n")->and($r['msg'])->toContain('o arquivo tem 2 linhas para essa flag');
});

test('mesmo valor não muda nada (idempotente)', function () {
    expect(EdicaoNoTexto::aplicar("x=1\n", 'ADICIONAR', 'x', '1'))->toMatchArray(['ok' => true, 'mudou' => false, 'msg' => 'a flag ja estava com esse valor, arquivo nao foi alterado']);
});

test('remover tira a linha inteira; flag inexistente não muda nada', function () {
    expect(EdicaoNoTexto::aplicar("a=1\r\nx = 2\r\nb=3", 'REMOVER', 'X', ''))->toMatchArray(['mudou' => true, 'novo' => "a=1\r\nb=3", 'msg' => '1 linha(s) removida(s)'])
        ->and(EdicaoNoTexto::aplicar("a=1\n", 'REMOVER', 'x', ''))->toMatchArray(['ok' => true, 'mudou' => false, 'msg' => 'a flag nao existe no arquivo, nada a remover']);
});

test('valida nome e valor como o painel e o robô antigos', function () {
    expect(EdicaoNoTexto::nomeValido('bloquear_importacao_ibscbs'))->toBeTrue()
        ->and(EdicaoNoTexto::nomeValido('jm.cliente-loja_2'))->toBeTrue()
        ->and(EdicaoNoTexto::nomeValido('2abc'))->toBeFalse()
        ->and(EdicaoNoTexto::nomeValido('a b'))->toBeFalse()
        ->and(EdicaoNoTexto::valorValido('valor com espaço'))->toBeTrue()
        ->and(EdicaoNoTexto::valorValido("duas\nlinhas"))->toBeFalse()
        ->and(EdicaoNoTexto::valorValido(str_repeat('x', 1001)))->toBeFalse();
});

test('as chaves sem a flag servem para conferir que nada mais mudou', function () {
    expect(EdicaoNoTexto::chavesSemFlag("b=2\nx=1\na=1\n", 'X'))->toBe(['a' => '1', 'b' => '2']);
});
