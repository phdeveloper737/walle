<?php

namespace Tests\Support;

use App\Sactus\Ssh\AlvoSsh;
use App\Sactus\Ssh\ConectorSsh;
use App\Sactus\Ssh\FalhaSsh;
use App\Sactus\Ssh\ResultadoComando;
use App\Sactus\Ssh\SessaoSsh;
use Closure;

/**
 * Servidores SSH de mentira para os testes do Sactus: cada "host|porta" tem senha, arquivos, ACLs
 * e entende os comandos que o walle manda (cat, test, getfacl, setfacl, md5sum, cp, base64, printf, yum...).
 * Anota todos os comandos executados, para conferir o que rodou (e o que NUNCA pode rodar).
 */
class ServidorSshFalso implements ConectorSsh
{
    /** @var array<string, array{senha: string, arquivos: array<string, string>, acl: array<string, list<string>>, donos: array<string, string>, setfacl: bool}> */
    public array $servidores = [];

    /** @var list<array{servidor: string, comando: string}> */
    public array $comandos = [];

    /** @var array<string, Closure(string, string): (ResultadoComando|null)> regra extra por servidor: (comando sem sudo, servidor) => resultado ou null */
    public array $regras = [];

    /** @param array<string, string> $arquivos */
    public function servidor(string $host, int $porta, string $senha, array $arquivos = [], bool $setfacl = true): self
    {
        $this->servidores["{$host}|{$porta}"] = ['senha' => $senha, 'arquivos' => $arquivos, 'acl' => [], 'donos' => [], 'setfacl' => $setfacl];

        return $this;
    }

    /** @param Closure(string, string): (ResultadoComando|null) $regra */
    public function regra(string $host, int $porta, Closure $regra): self
    {
        $this->regras["{$host}|{$porta}"] = $regra;

        return $this;
    }

    public function arquivo(string $host, int $porta, string $caminho): ?string
    {
        return $this->servidores["{$host}|{$porta}"]['arquivos'][$caminho] ?? null;
    }

    /** @return list<string> */
    public function comandosEm(string $host, int $porta): array
    {
        return array_values(array_map(fn (array $c): string => $c['comando'], array_filter($this->comandos, fn (array $c): bool => $c['servidor'] === "{$host}|{$porta}")));
    }

    public function abrir(AlvoSsh $alvo): SessaoSsh
    {
        $chave = $alvo->chave();

        if (! isset($this->servidores[$chave])) {
            throw FalhaSsh::handshake();
        }

        if ($this->servidores[$chave]['senha'] !== $alvo->senha) {
            throw FalhaSsh::autenticacao();
        }

        $falso = $this;

        return new class($falso, $chave) implements SessaoSsh
        {
            public function __construct(private ServidorSshFalso $falso, private string $chave) {}

            public function executar(string $comando, int $timeoutSegundos): ResultadoComando
            {
                $this->falso->comandos[] = ['servidor' => $this->chave, 'comando' => $comando];

                return $this->falso->interpretar($this->chave, $comando);
            }

            public function fechar(): void {}
        };
    }

    public function interpretar(string $chave, string $comando): ResultadoComando
    {
        $c = (string) preg_replace('~^sudo -n ~', '', $comando);

        // sudo -n sh -c 'cat ... > ...'
        if (preg_match("~^sh -c '(.*)'$~s", $c, $m)) {
            $c = str_replace("'\\''", "'", $m[1]);
        }

        if (isset($this->regras[$chave]) && ($r = ($this->regras[$chave])($c, $chave)) !== null) {
            return $r;
        }

        $s = &$this->servidores[$chave];
        preg_match_all("~'([^']*)'~", $c, $q);
        $args = $q[1];

        return match (true) {
            str_starts_with($c, 'cat ') && str_contains($c, ' > ') => $this->escrever($s, $args[0], $args[1]),
            str_starts_with($c, 'cat ') => isset($s['arquivos'][$args[0]]) ? new ResultadoComando(0, $s['arquivos'][$args[0]]) : new ResultadoComando(1, '', 'No such file or directory'),
            str_starts_with($c, 'test -f ') => new ResultadoComando(isset($s['arquivos'][$args[0]]) ? 0 : 1),
            str_starts_with($c, 'getfacl ') => isset($s['arquivos'][$args[0]])
                ? new ResultadoComando(0, "# file: {$args[0]}\nuser::rw-\n".implode('', array_map(fn ($u) => "user:{$u}:---\n", $s['acl'][$args[0]] ?? [])))
                : new ResultadoComando(1),
            str_starts_with($c, 'stat -c %U ') => new ResultadoComando(0, ($s['donos'][$args[0]] ?? 'root')."\n"),
            str_starts_with($c, 'command -v setfacl') => new ResultadoComando($s['setfacl'] ? 0 : 1),
            $c === 'yum install -y acl' => (function () use (&$s) {
                $s['setfacl'] = true;

                return new ResultadoComando(0, 'Installed: acl');
            })(),
            $c === 'yum -y update' => new ResultadoComando(0, "Complete!\n"),
            str_starts_with($c, 'setfacl -m ') => (function () use (&$s, $args) {
                $s['acl'][$args[1]][] = explode(':', $args[0])[1];

                return new ResultadoComando(0);
            })(),
            str_starts_with($c, 'md5sum ') => isset($s['arquivos'][$args[0]]) ? new ResultadoComando(0, md5($s['arquivos'][$args[0]])."  {$args[0]}\n") : new ResultadoComando(1),
            str_starts_with($c, 'cp -p ') => (function () use (&$s, $args) {
                $s['arquivos'][$args[1]] = $s['arquivos'][$args[0]];

                return new ResultadoComando(0);
            })(),
            str_starts_with($c, 'rm -f ') => (function () use (&$s, $args) {
                foreach ($args as $a) {
                    unset($s['arquivos'][$a]);
                }

                return new ResultadoComando(0);
            })(),
            str_starts_with($c, 'printf %s ') => (function () use (&$s, $args) {
                $s['arquivos'][$args[1]] = ($s['arquivos'][$args[1]] ?? '').$args[0];

                return new ResultadoComando(0);
            })(),
            str_starts_with($c, 'base64 -d ') => (function () use (&$s, $args) {
                $s['arquivos'][$args[1]] = (string) base64_decode($s['arquivos'][$args[0]] ?? '');

                return new ResultadoComando(0);
            })(),
            // Atualização do ERP: sucesso por padrão
            str_starts_with($c, 'sshpass '), str_starts_with($c, 'chown '), str_starts_with($c, 'chmod ') => new ResultadoComando(0, 'ok'),
            default => new ResultadoComando(127, '', 'comando desconhecido no servidor falso: '.$c),
        };
    }

    /** @param array{arquivos: array<string, string>} $s */
    private function escrever(array &$s, string $origem, string $destino): ResultadoComando
    {
        $s['arquivos'][$destino] = $s['arquivos'][$origem] ?? '';

        return new ResultadoComando(0);
    }
}
