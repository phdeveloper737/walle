<?php

namespace App\Sactus;

use App\Sactus\Ssh\AlvoSsh;

/**
 * Um servidor de cliente visto pelo robô do Sactus, a partir da linha do informacao_tecnica_dataserver
 * (Help Desk). Mesmas regras do sactus.php antigo: elegibilidade, ACL, ERP, VPN e ordem dos alvos SSH.
 */
final readonly class ClienteSactus
{
    /** "Vazio" na coluna pasta_multiempresa do legado (servidor NORMAL). */
    private const VAZIOS = ['N', 'NA', 'NAO', 'NO', 'NULL', '0', '-', '--', 'NONE', 'VAZIO'];

    /** @param array<string, string> $campos colunas do informacao_tecnica_dataserver, já com trim */
    public function __construct(
        public int $id,
        private array $campos,
    ) {}

    /** @param array<string, mixed> $linha */
    public static function daLinha(array $linha): self
    {
        $campos = [];

        foreach ($linha as $coluna => $valor) {
            $campos[(string) $coluna] = trim((string) $valor);
        }

        return new self((int) ($linha['id_client'] ?? $linha['fk_c_cliente'] ?? 0), $campos);
    }

    public function campo(string $nome): string
    {
        return $this->campos[$nome] ?? '';
    }

    /** DOCKER quando pasta_multiempresa está preenchida (S); N, vazio e afins são NORMAL. */
    public function ehDocker(): bool
    {
        $valor = strtr($this->campo('pasta_multiempresa'), ['ã' => 'a', 'Ã' => 'A', 'á' => 'a', 'Á' => 'A', 'à' => 'a', 'À' => 'A', 'â' => 'a', 'Â' => 'A']);

        return $valor !== '' && ! in_array(strtoupper($valor), self::VAZIOS, true);
    }

    public function tipo(): string
    {
        return $this->ehDocker() ? 'DOCKER' : 'NORMAL';
    }

    public function precisaAcl(): bool
    {
        return (bool) config('walle.sactus.acl.ativo') && config('walle.sactus.acl.regras') !== []
            && strtoupper($this->campo('service_acl')) === 'S';
    }

    public function precisaAtualizarErp(bool $comandosConfigurados): bool
    {
        return (bool) config('walle.sactus.erp.ativo') && $comandosConfigurados
            && strtoupper($this->campo('atualizar_erp')) === 'S';
    }

    /** IP da VPN (ip_interno_servidor na faixa 10.8.0.), ou '' quando não se aplica. */
    public function ipVpn(): string
    {
        $ip = $this->campo('ip_interno_servidor');

        return str_starts_with($ip, (string) config('walle.sactus.erp.vpn_prefixo')) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            ? $ip
            : '';
    }

    /** Atualiza o ERP só pela VPN: atualizar_erp = S com IP da VPN cadastrado (não depende de local_servidor). */
    public function erpPorVpn(bool $comandosConfigurados): bool
    {
        return $this->precisaAtualizarErp($comandosConfigurados) && $this->ipVpn() !== '';
    }

    /** Segunda barreira do filtro (a primeira é o SQL): Docker, servidor na empresa, ERP pela VPN ou ACL. */
    public function elegivel(bool $comandosErpConfigurados): bool
    {
        return $this->ehDocker()
            || strtoupper($this->campo('local_servidor')) === 'EMPRESA'
            || $this->erpPorVpn($comandosErpConfigurados)
            || $this->precisaAcl();
    }

    /**
     * Alvos SSH na ordem de tentativa. Docker primeiro quando o servidor é Docker, normal primeiro
     * quando não é; com ERP pela VPN, os alvos da VPN vão na frente de todos. Sem repetidos.
     *
     * @return list<AlvoSsh>
     */
    public function alvos(bool $comandosErpConfigurados): array
    {
        [$uD, $sD, $pD] = [$this->campo('usuario_ssh_docker'), $this->campo('senha_ssh_docker'), $this->campo('porta_ssh_docker')];
        [$uN, $sN] = [$this->campo('usuario_ssh'), $this->campo('senha_ssh')];
        $pN = $this->campo('porta_ssh') !== '' ? $this->campo('porta_ssh') : '22';

        // Sem porta_ssh_docker o conjunto Docker é descartado: na 22 a credencial do contêiner só dá "Permission denied"
        $docker = $uD !== '' && $sD !== '' && $pD !== ''
            ? [self::alvo('DOCKER', $this->campo('servidor_docker'), $pD, $uD, $sD), self::alvo('DOCKER', $this->campo('ip_externo_servidor'), $pD, $uD, $sD)]
            : [];

        $normal = $uN !== '' && $sN !== '' ? [self::alvo('NORMAL', $this->campo('ip_externo_servidor'), $pN, $uN, $sN)] : [];

        $estrito = (bool) config('walle.sactus.alvo_estrito');
        $ordem = $this->ehDocker()
            ? ($estrito ? $docker : [...$docker, ...$normal])
            : ($estrito ? $normal : [...$normal, ...$docker]);

        if ($this->erpPorVpn($comandosErpConfigurados)) {
            $vpn = [];

            if ($uN !== '' && $sN !== '') {
                $vpn[] = self::alvo('VPN', $this->ipVpn(), $pN, $uN, $sN);
            }

            if ($uD !== '' && $sD !== '' && $pD !== '') {
                $vpn[] = self::alvo('VPN', $this->ipVpn(), $pD, $uD, $sD);
            }

            $ordem = [...$vpn, ...$ordem];
        }

        $alvos = [];

        foreach ($ordem as $alvo) {
            if ($alvo !== null) {
                $alvos[strtolower("{$alvo->host}|{$alvo->porta}|{$alvo->usuario}")] ??= $alvo;
            }
        }

        return array_values($alvos);
    }

    /**
     * Host sem esquema nem caminho, com a porta embutida ("host:2222") separada.
     *
     * @return array{host: string, porta: int|null}
     */
    public static function normalizarHost(string $bruto): array
    {
        $host = trim($bruto);

        if ($host === '') {
            return ['host' => '', 'porta' => null];
        }

        $host = (string) preg_replace('~^[a-z][a-z0-9+.-]*://~i', '', $host);
        $host = trim((string) preg_replace('~[/?#].*$~', '', $host));

        if (preg_match('~^([^:\[\]]+):(\d{1,5})$~', $host, $m)) {
            return ['host' => $m[1], 'porta' => (int) $m[2]];
        }

        return ['host' => $host, 'porta' => null];
    }

    private static function alvo(string $metodo, string $hostBruto, string $portaPadrao, string $usuario, #[\SensitiveParameter] string $senha): ?AlvoSsh
    {
        $host = self::normalizarHost($hostBruto);
        $porta = $host['porta'] ?? (int) $portaPadrao;

        // O host vai para linha de comando: só letras, dígitos, ponto, hífen e _ (barra injeção vinda do cadastro)
        if ($host['host'] === '' || $usuario === '' || $senha === '' || $porta <= 0 || $porta > 65535
            || ! preg_match('~^[A-Za-z0-9._-]+$~', $host['host'])) {
            return null;
        }

        return new AlvoSsh($metodo, $host['host'], $porta, $usuario, $senha);
    }
}
