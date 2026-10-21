<?php

use App\Contingencias\Cliente\ParametrosConexao;

/** @param array<string, mixed> $servidor */
function parametrosDoCliente(int $clienteId, array $servidor): ?ParametrosConexao
{
    return ParametrosConexao::doCliente($clienteId, $servidor, 'senha-docker-jm', ['192.168.10.244', '192.168.10.235']);
}

test('servidor na empresa pela VPN usa IP, senha, banco e porta do cadastro', function () {
    $p = parametrosDoCliente(10, [
        'local_servidor' => 'EMPRESA', 'pasta_multiempresa' => 'N', 'ip_externo_servidor' => '10.8.0.5',
        'senha_mysql' => 'senha-cliente', 'banco_mysql' => 'actus', 'porta_mysql' => '3307',
    ]);

    expect($p)->toEqual(new ParametrosConexao('10.8.0.5', 3307, 'root', 'senha-cliente', 'actus'));
});

test('a porta do cadastro só vale com 4 dígitos ou mais e diferente de 3306', function () {
    $servidor = ['local_servidor' => 'EMPRESA', 'pasta_multiempresa' => 'N', 'ip_externo_servidor' => '192.168.99.10', 'senha_mysql' => 's', 'banco_mysql' => 'actus'];

    expect(parametrosDoCliente(10, [...$servidor, 'porta_mysql' => '330'])->porta)->toBe(3306)
        ->and(parametrosDoCliente(10, [...$servidor, 'porta_mysql' => ''])->porta)->toBe(3306)
        ->and(parametrosDoCliente(10, [...$servidor, 'porta_mysql' => '13306'])->porta)->toBe(13306);
});

test('servidor na empresa sem IP da VPN não conecta', function () {
    expect(parametrosDoCliente(10, [
        'local_servidor' => 'EMPRESA', 'pasta_multiempresa' => 'N', 'ip_externo_servidor' => '200.1.2.3',
        'senha_mysql' => 's', 'banco_mysql' => 'actus',
    ]))->toBeNull();
});

test('Docker da JM: root com a senha fixa, banco actus{id} e o .244 quando o cadastro não diz', function () {
    expect(parametrosDoCliente(123, ['local_servidor' => 'OPEN', 'pasta_multiempresa' => 'S']))
        ->toEqual(new ParametrosConexao('192.168.10.244', 3306, 'root', 'senha-docker-jm', 'actus123'));

    expect(parametrosDoCliente(123, [
        'local_servidor' => 'OPEN', 'servidor_docker' => '192.168.10.235', 'porta_mysql_docker' => '3310', 'banco_mysql_docker' => 'actus_x',
    ]))->toEqual(new ParametrosConexao('192.168.10.235', 3310, 'root', 'senha-docker-jm', 'actus_x'));
});

test('outro Docker usa usuário, senha e banco do cadastro', function () {
    expect(parametrosDoCliente(5, [
        'local_servidor' => 'OPEN', 'servidor_docker' => '10.20.30.40',
        'usuario_mysql_docker' => 'usuario', 'senha_mysql_docker' => 'senha', 'banco_mysql_docker' => 'actus5',
    ]))->toEqual(new ParametrosConexao('10.20.30.40', 3306, 'usuario', 'senha', 'actus5'));

    // Sem senha no cadastro: não dá para conectar
    expect(parametrosDoCliente(5, ['local_servidor' => 'OPEN', 'servidor_docker' => '10.20.30.40', 'usuario_mysql_docker' => 'usuario']))->toBeNull();
});

test('exceções do sistema antigo: clientes forçados na empresa e o banco fixo do 1974', function () {
    $servidor = [
        'local_servidor' => 'OPEN', 'pasta_multiempresa' => 'S', 'ip_externo_servidor' => '10.8.0.9',
        'senha_mysql' => 's', 'banco_mysql' => 'actus',
    ];

    expect(parametrosDoCliente(1634, $servidor)->host)->toBe('10.8.0.9')
        ->and(parametrosDoCliente(1974, [...$servidor, 'local_servidor' => 'EMPRESA', 'pasta_multiempresa' => 'N'])->banco)->toBe('actus1944');
});
