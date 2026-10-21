<?php

use App\Sactus\ArquivoCfg;

test('lê chave=valor e chave: valor, com seções, comentários e BOM', function () {
    $cfg = "\xEF\xBB\xBF# comentário\nlicenciado=S\n; outro\nhost : 10.0.0.1\n  porta = \"3306\"  \n\n[backup]\npath=/home\n";

    expect(ArquivoCfg::ler($cfg))->toBe([
        'licenciado' => 'S',
        'host' => '10.0.0.1',
        'porta' => '3306',
        'backup' => ['path' => '/home'],
    ]);
});

test('aceita o arquivo em JSON e devolve vazio para arquivo vazio', function () {
    expect(ArquivoCfg::ler('{"a": "1", "b": {"c": 2}}'))->toBe(['a' => '1', 'b' => ['c' => 2]])
        ->and(ArquivoCfg::ler("  \n"))->toBe([]);
});

test('reconhece as chaves sensíveis como o painel antigo', function () {
    expect(ArquivoCfg::sensivel('jm_database_password'))->toBeTrue()
        ->and(ArquivoCfg::sensivel('telnet_password'))->toBeTrue()
        ->and(ArquivoCfg::sensivel('senha_banco'))->toBeTrue()
        ->and(ArquivoCfg::sensivel('api_token'))->toBeTrue()
        ->and(ArquivoCfg::sensivel('licenciado'))->toBeFalse();
});

test('mascara só o valor das chaves sensíveis e mantém o resto do arquivo', function () {
    $cfg = "licenciado=S\njm_database_password = segredo\n# password=comentario\ntoken:abc\nvazia_password=\r\nfim=1";

    expect(ArquivoCfg::mascarar($cfg))->toBe("licenciado=S\njm_database_password = ••••••\n# password=comentario\ntoken:••••••\nvazia_password=\r\nfim=1");
});
