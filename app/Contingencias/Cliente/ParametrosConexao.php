<?php

namespace App\Contingencias\Cliente;

/**
 * Como chegar no MySQL do servidor de um cliente: mesmas regras do ClienteConnection do sistema antigo,
 * inclusive as exceções fixas por cliente.
 */
final readonly class ParametrosConexao
{
    /** Clientes que o sistema antigo trata como servidor na empresa (VPN), seja qual for o cadastro. */
    public const FORCADOS_NA_EMPRESA = [1634, 1647, 1655, 1658, 1781, 1811];

    /** Exceções de banco do sistema antigo: cliente => banco actus. */
    public const BANCOS_FIXOS = [1892 => 'actus1434', 1974 => 'actus1944'];

    public function __construct(
        public string $host,
        public int $porta,
        public string $usuario,
        #[\SensitiveParameter] public string $senha,
        public string $banco,
    ) {}

    /**
     * @param  array<string, mixed>  $servidor  Linha do informacao_tecnica_dataserver (Help Desk)
     * @param  list<string>  $hostsDockerJm  Docker da JM; o primeiro é o padrão
     * @return self|null null = cadastro incompleto para conectar
     */
    public static function doCliente(int $clienteId, array $servidor, #[\SensitiveParameter] string $senhaDockerJm, array $hostsDockerJm): ?self
    {
        $campo = fn (string $nome): string => trim((string) ($servidor[$nome] ?? ''));

        $local = $campo('local_servidor');
        $docker = $campo('pasta_multiempresa');
        $ip = $campo('ip_externo_servidor');

        if (in_array($clienteId, self::FORCADOS_NA_EMPRESA, true)) {
            $local = 'EMPRESA';
            $docker = 'N';
        }

        // Servidor na empresa do cliente, alcançado pela VPN
        if ($local === 'EMPRESA' && $docker === 'N') {
            if (! str_starts_with($ip, '10.8.0.') && ! str_starts_with($ip, '192.168.99.')) {
                return null;
            }

            $porta = $campo('porta_mysql');

            return self::seCompleto(
                host: $ip,
                porta: ((int) $porta > 0 && strlen($porta) >= 4 && (int) $porta !== 3306) ? (int) $porta : 3306,
                usuario: 'root',
                senha: $campo('senha_mysql'),
                banco: $clienteId === 1974 ? 'actus1944' : $campo('banco_mysql'),
            );
        }

        // Servidor em Docker
        $host = $campo('servidor_docker') ?: $hostsDockerJm[0];

        if (in_array($host, $hostsDockerJm, true)) {
            return self::seCompleto(
                host: $host,
                porta: (int) $campo('porta_mysql_docker') ?: 3306,
                usuario: 'root',
                senha: $senhaDockerJm,
                banco: $campo('banco_mysql_docker') ?: "actus{$clienteId}",
            );
        }

        return self::seCompleto(
            host: $host,
            porta: 3306,
            usuario: $campo('usuario_mysql_docker'),
            senha: $campo('senha_mysql_docker'),
            banco: $campo('banco_mysql_docker'),
        );
    }

    private static function seCompleto(string $host, int $porta, string $usuario, #[\SensitiveParameter] string $senha, string $banco): ?self
    {
        if ($host === '' || $porta <= 0 || $usuario === '' || $senha === '' || $banco === '') {
            return null;
        }

        return new self($host, $porta, $usuario, $senha, $banco);
    }
}
