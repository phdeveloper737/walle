<?php

use App\Sactus\ClienteSactus;
use App\Sactus\RotinaErp;

/** @param array<string, string> $campos */
function clienteSactus(array $campos): ClienteSactus
{
    return ClienteSactus::daLinha(['id_client' => 7, ...$campos]);
}

test('Docker pela pasta_multiempresa preenchida; N, vazio e afins são NORMAL', function () {
    expect(clienteSactus(['pasta_multiempresa' => 'S'])->ehDocker())->toBeTrue()
        ->and(clienteSactus(['pasta_multiempresa' => 'actus7'])->ehDocker())->toBeTrue()
        ->and(clienteSactus(['pasta_multiempresa' => 'N'])->ehDocker())->toBeFalse()
        ->and(clienteSactus(['pasta_multiempresa' => 'Não'])->ehDocker())->toBeFalse()
        ->and(clienteSactus(['pasta_multiempresa' => ''])->ehDocker())->toBeFalse();
});

test('host normalizado: sem esquema e caminho, com a porta embutida separada', function () {
    expect(ClienteSactus::normalizarHost('http://meuservidor.com:2222/painel'))->toBe(['host' => 'meuservidor.com', 'porta' => 2222])
        ->and(ClienteSactus::normalizarHost(' 10.8.0.5 '))->toBe(['host' => '10.8.0.5', 'porta' => null]);
});

test('ordem dos alvos: Docker primeiro no Docker, normal primeiro no normal, sem repetir', function () {
    $credenciais = [
        'ip_externo_servidor' => '200.1.1.1', 'porta_ssh' => '', 'usuario_ssh' => 'root', 'senha_ssh' => 'n',
        'servidor_docker' => '192.168.10.244', 'porta_ssh_docker' => '2207', 'usuario_ssh_docker' => 'root', 'senha_ssh_docker' => 'd',
    ];

    $docker = array_map(fn ($a) => $a->descricao(), clienteSactus([...$credenciais, 'pasta_multiempresa' => 'S'])->alvos(true));
    $normal = array_map(fn ($a) => $a->descricao(), clienteSactus([...$credenciais, 'pasta_multiempresa' => 'N'])->alvos(true));

    expect($docker)->toBe(['DOCKER root@192.168.10.244:2207', 'DOCKER root@200.1.1.1:2207', 'NORMAL root@200.1.1.1:22'])
        ->and($normal)->toBe(['NORMAL root@200.1.1.1:22', 'DOCKER root@192.168.10.244:2207', 'DOCKER root@200.1.1.1:2207']);
});

test('sem porta_ssh_docker o conjunto Docker é descartado; host com caractere estranho também', function () {
    $c = clienteSactus(['pasta_multiempresa' => 'S', 'ip_externo_servidor' => 'host;rm -rf /', 'usuario_ssh' => 'root', 'senha_ssh' => 'n',
        'servidor_docker' => '10.0.0.1', 'usuario_ssh_docker' => 'root', 'senha_ssh_docker' => 'd']);

    expect($c->alvos(true))->toBe([]);
});

test('ERP pela VPN: alvos da VPN na frente de todos', function () {
    config(['walle.sactus.erp.senha' => 'x']);
    $c = clienteSactus(['local_servidor' => 'EMPRESA', 'ip_externo_servidor' => '200.1.1.1', 'usuario_ssh' => 'root', 'senha_ssh' => 'n',
        'ip_interno_servidor' => '10.8.0.15', 'atualizar_erp' => 'S']);

    expect($c->erpPorVpn(true))->toBeTrue()
        ->and(array_map(fn ($a) => $a->descricao(), $c->alvos(true)))->toBe(['VPN root@10.8.0.15:22', 'NORMAL root@200.1.1.1:22'])
        // Sem os comandos do ERP configurados, nada de VPN
        ->and(array_map(fn ($a) => $a->descricao(), $c->alvos(false)))->toBe(['NORMAL root@200.1.1.1:22']);
});

test('elegível: Docker, servidor na empresa, ERP pela VPN ou ACL', function () {
    expect(clienteSactus(['pasta_multiempresa' => 'S'])->elegivel(true))->toBeTrue()
        ->and(clienteSactus(['local_servidor' => 'empresa'])->elegivel(true))->toBeTrue()
        ->and(clienteSactus(['local_servidor' => 'OPEN', 'service_acl' => 'S'])->elegivel(true))->toBeTrue()
        ->and(clienteSactus(['local_servidor' => 'OPEN', 'atualizar_erp' => 'S', 'ip_interno_servidor' => '10.8.0.9'])->elegivel(true))->toBeTrue()
        ->and(clienteSactus(['local_servidor' => 'OPEN', 'atualizar_erp' => 'S'])->elegivel(true))->toBeFalse();
});

test('o comando do rsync vem do .env e a senha nunca aparece mascarada', function () {
    config(['walle.sactus.erp.senha' => null]);
    expect(app(RotinaErp::class)->comandos())->toBe([]);

    config(['walle.sactus.erp.senha' => 'segredo123']);
    $comandos = app(RotinaErp::class)->comandos();

    expect($comandos[0])->toBe("sshpass -p'segredo123' rsync -Cravzp -e 'ssh -p 22' root@192.168.10.220:/var/sistema/* '/var/sistema/'")
        ->and(array_slice($comandos, 1))->toBe(['chown -R apache.apache /var/sistema', 'chmod -R 777 /var/sistema'])
        ->and(RotinaErp::mascarar($comandos[0]))->not->toContain('segredo123')->toContain("-p'***'");
});
