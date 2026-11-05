<?php

namespace App\Contingencias\Cliente;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use PDO;
use Throwable;

/**
 * Abre a conexão com o MySQL do servidor de um cliente, para leitura (substitui o ClienteConnection).
 *
 * Ordem igual à do sistema antigo: confere a porta, entra no banco do cadastro (ou sem banco),
 * acha o banco do Sactus (actus) e confere a loja na tabela store.
 */
class ConectorDeCliente
{
    /** @param array<string, mixed> $servidor Linha do informacao_tecnica_dataserver + c_cliente (Help Desk) */
    public function conectar(int $clienteId, array $servidor): ConexaoComCliente
    {
        $parametros = ParametrosConexao::doCliente(
            $clienteId,
            $servidor,
            (string) config('services.clientes.docker_senha_root'),
            config('services.clientes.docker_hosts'),
        ) ?? throw FalhaConexaoCliente::cadastroIncompleto([
            'etapa' => 'cadastro',
            'local_servidor' => trim((string) ($servidor['local_servidor'] ?? '')),
        ]);

        $alvo = "cliente {$clienteId} em {$parametros->host}:{$parametros->porta}";
        $segredos = $this->segredos($parametros);
        $timeout = (int) config('services.clientes.timeout', 10);
        $tentativa = [
            'host' => $parametros->host,
            'porta' => $parametros->porta,
            'usuario' => $parametros->usuario,
            'banco_cadastro' => $parametros->banco,
            'local_servidor' => trim((string) ($servidor['local_servidor'] ?? '')),
            'timeout_porta_segundos' => $timeout / 2,
            'timeout_pdo_segundos' => $timeout,
        ];

        $erroSocket = ['codigo' => 0, 'mensagem' => ''];

        if (! $this->portaAberta($parametros->host, $parametros->porta, $erroSocket, $segredos)) {
            throw FalhaConexaoCliente::servidorNaoResponde($alvo, [...$tentativa, 'etapa' => 'porta', 'socket' => $erroSocket]);
        }

        $erros = [];
        $conexao = $this->abrir($parametros, $parametros->banco, $erros, $segredos)
            ?? $this->abrir($parametros, '', $erros, $segredos)
            ?? throw FalhaConexaoCliente::acessoNegado($alvo, [...$tentativa, 'etapa' => 'login', 'erros' => $erros]);

        $bancos = $this->bancos($conexao);
        $bancoActus = $this->resolverBancoActus($clienteId, $parametros->banco, $bancos);

        if ($bancoActus === null) {
            $conexao->disconnect();

            throw FalhaConexaoCliente::semBancoActus($alvo.' | bancos: '.implode(', ', $bancos), [
                ...$tentativa,
                'etapa' => 'banco_actus',
                'bancos_encontrados' => array_values($bancos),
                'candidatos_actus' => $this->candidatosActus($clienteId, $parametros->banco),
            ]);
        }

        $bancoActus = $this->conferirLoja($conexao, $clienteId, $servidor, $bancoActus, $bancos);

        return new ConexaoComCliente($conexao, $bancoActus);
    }

    /**
     * @param  array{codigo: int, mensagem: string}  $erro  preenchido quando a porta não abre
     * @param  list<string>  $segredos
     */
    private function portaAberta(string $host, int $porta, array &$erro, array $segredos): bool
    {
        $socket = @fsockopen($host, $porta, $codigo, $mensagem, (float) config('services.clientes.timeout', 10) / 2);

        if ($socket === false) {
            $erro = ['codigo' => (int) $codigo, 'mensagem' => TextoSemSenha::limpar((string) $mensagem, $segredos)];

            return false;
        }

        fclose($socket);

        return true;
    }

    /** @return list<string> senhas que nunca podem aparecer em texto de erro */
    private function segredos(ParametrosConexao $parametros): array
    {
        return array_values(array_filter(
            [$parametros->senha, (string) config('services.clientes.docker_senha_root')],
            fn (string $segredo): bool => $segredo !== '',
        ));
    }

    /** @return list<string> */
    private function candidatosActus(int $clienteId, string $bancoCadastro): array
    {
        $candidatos = [];

        if (isset(ParametrosConexao::BANCOS_FIXOS[$clienteId])) {
            $candidatos[] = ParametrosConexao::BANCOS_FIXOS[$clienteId];
        }

        if ($bancoCadastro !== '' && stripos($bancoCadastro, 'actus') === 0) {
            $candidatos[] = $bancoCadastro;
        }

        return array_values(array_unique([...$candidatos, "actus{$clienteId}", 'actus']));
    }

    /**
     * @param  list<array<string, mixed>>  $erros  recebe a falha de cada tentativa
     * @param  list<string>  $segredos
     */
    private function abrir(ParametrosConexao $parametros, string $banco, array &$erros, array $segredos): ?Connection
    {
        try {
            $conexao = DB::build([
                'driver' => 'mysql',
                'host' => $parametros->host,
                'port' => $parametros->porta,
                'database' => $banco,
                'username' => $parametros->usuario,
                'password' => $parametros->senha,
                // latin1, como o sistema antigo: os clientes guardam UTF-8 dentro de colunas latin1 e,
                // lendo em utf8, o MySQL converteria de novo ("RejeiÃ§Ã£o"). Texto::utf8() acerta o resto.
                'charset' => 'latin1',
                'collation' => null,
                'prefix' => '',
                'strict' => false,
                'options' => [PDO::ATTR_TIMEOUT => (int) config('services.clientes.timeout', 10)],
            ]);

            if (! $conexao instanceof Connection) {
                return null;
            }

            $conexao->getPdo();
            // Nenhuma consulta fica pendurada mais de 30 s no servidor do cliente
            $conexao->statement('SET SESSION wait_timeout = 30, net_read_timeout = 30, net_write_timeout = 30');

            return $conexao;
        } catch (Throwable $erro) {
            $erros[] = [
                'banco' => $banco,
                'classe' => $erro::class,
                'codigo' => $erro->getCode(),
                'mensagem' => TextoSemSenha::limpar($erro->getMessage(), $segredos),
            ];

            return null;
        }
    }

    /** @return array<string, string> nome em minúsculas => nome real */
    private function bancos(Connection $conexao): array
    {
        try {
            $nomes = array_map(fn (object $linha): string => (string) array_values((array) $linha)[0], $conexao->select('SHOW DATABASES'));
        } catch (Throwable) {
            return [];
        }

        return array_combine(array_map(strtolower(...), $nomes), $nomes);
    }

    /** @param array<string, string> $bancos */
    private function resolverBancoActus(int $clienteId, string $bancoCadastro, array $bancos): ?string
    {
        $candidatos = [];

        if (isset(ParametrosConexao::BANCOS_FIXOS[$clienteId])) {
            $candidatos[] = ParametrosConexao::BANCOS_FIXOS[$clienteId];
        }

        if ($bancoCadastro !== '' && stripos($bancoCadastro, 'actus') === 0) {
            $candidatos[] = $bancoCadastro;
        }

        $candidatos[] = "actus{$clienteId}";
        $candidatos[] = 'actus';

        foreach (array_unique($candidatos) as $candidato) {
            if (isset($bancos[strtolower($candidato)])) {
                return $bancos[strtolower($candidato)];
            }
        }

        return null;
    }

    /**
     * Como o validateCnpj do sistema antigo: se a loja do cadastro não está na tabela store do banco encontrado,
     * tenta o outro banco (actus <-> actus{id}). Se nenhum tiver, segue com o primeiro (o sistema antigo também seguia).
     *
     * @param  array<string, mixed>  $servidor
     * @param  array<string, string>  $bancos
     */
    private function conferirLoja(Connection $conexao, int $clienteId, array $servidor, string $bancoActus, array $bancos): string
    {
        $loja = (int) ($servidor['loja'] ?? 0);

        if (trim((string) ($servidor['servidor_multiempresa'] ?? '')) === 'V' || $loja <= 0) {
            return $bancoActus;
        }

        if ($this->temLoja($conexao, $bancoActus, $loja)) {
            return $bancoActus;
        }

        $alternativo = strlen($bancoActus) > 5 ? 'actus' : "actus{$clienteId}";

        if (isset($bancos[$alternativo]) && $this->temLoja($conexao, $bancos[$alternativo], $loja)) {
            return $bancos[$alternativo];
        }

        return $bancoActus;
    }

    private function temLoja(Connection $conexao, string $banco, int $loja): bool
    {
        try {
            return $conexao->selectOne('SELECT cnpj FROM `'.str_replace('`', '', $banco).'`.store WHERE id = ? LIMIT 1', [$loja]) !== null;
        } catch (Throwable) {
            return false;
        }
    }
}
