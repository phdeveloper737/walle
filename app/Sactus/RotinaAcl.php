<?php

namespace App\Sactus;

use App\Sactus\Ssh\AlvoSsh;
use App\Sactus\Ssh\SessaoSsh;
use Illuminate\Support\Facades\Log;

/**
 * Tira de um usuário todo o acesso a arquivos do servidor do cliente (setfacl u:<usuario>:---),
 * para os clientes com service_acl = 'S'. Mesmo fluxo do aplicarAcl() do antigo:
 *   A. para cada arquivo: existe? a ACL já está aplicada?
 *   B. havendo pendência, garante o setfacl (instala o pacote acl, uma vez por sessão);
 *   C. aplica e confere cada pendente com o getfacl.
 *
 * Diferença de propósito: o antigo rodava "yum -y update" antes de instalar o pacote acl.
 * No walle o yum update é uma ação manual por cliente, pedida por um admin no painel.
 */
class RotinaAcl
{
    public const PRIORIDADE = [
        'falha-instalacao' => 1,
        'falha-setfacl' => 2,
        'nao-confirmada' => 3,
        'sem-resposta' => 4,
        'arquivo-inexistente' => 5,
        'aplicada-pos-instalacao' => 6,
        'aplicada' => 7,
        'ja-aplicada' => 8,
    ];

    public const ROTULOS = [
        'ja-aplicada' => 'Já estava aplicada',
        'aplicada' => 'Aplicada',
        'aplicada-pos-instalacao' => 'Aplicada após instalar o pacote acl',
        'arquivo-inexistente' => 'Arquivo não existe no servidor',
        'sem-resposta' => 'Servidor não respondeu',
        'nao-confirmada' => 'Aplicada, mas o getfacl não confirmou',
        'falha-setfacl' => 'O setfacl falhou',
        'falha-instalacao' => 'Não conseguiu instalar o pacote acl',
    ];

    public function aplicar(SessaoSsh $sessao, AlvoSsh $alvo, int $clienteId): ResultadoRotina
    {
        $sudo = $alvo->sudo();
        $timeout = (int) config('walle.sactus.acl.comando_segundos');
        $regras = (array) config('walle.sactus.acl.regras');
        $itens = [];
        $pendentes = [];

        // A. diagnóstico por arquivo (nenhum yum ainda)
        foreach ($regras as $arquivo => $usuario) {
            [$arquivo, $usuario] = [trim((string) $arquivo), trim((string) $usuario)];

            if ($arquivo === '' || $usuario === '') {
                continue;
            }

            $arg = escapeshellarg($arquivo);
            $existe = $sessao->executar('test -f '.$arg, $timeout);

            if ($existe->rc === null) {
                $itens[$arquivo] = self::item($arquivo, $usuario, 'N', 'sem-resposta', 'sem resposta do servidor ao verificar o arquivo');
            } elseif (! $existe->ok()) {
                $itens[$arquivo] = self::item($arquivo, $usuario, 'N', 'arquivo-inexistente', 'arquivo nao encontrado no servidor');
            } elseif ($this->jaAplicada($sessao, $sudo, $arg, $usuario, $timeout) === true) {
                $itens[$arquivo] = $this->conferirDono($sessao, $arg, $usuario, self::item($arquivo, $usuario, 'S', 'ja-aplicada'), $timeout);
            } else {
                $pendentes[] = [$arquivo, $usuario, $arg];
            }
        }

        // B. setfacl disponível (uma vez por sessão, só se tiver pendência)
        $instalou = false;

        if ($pendentes !== []) {
            $garantia = $this->garantirSetfacl($sessao, $sudo, $clienteId, $timeout);
            $instalou = $garantia['instalou'];

            if (! $garantia['ok']) {
                foreach ($pendentes as [$arquivo, $usuario]) {
                    $itens[$arquivo] = self::item($arquivo, $usuario, 'N', 'falha-instalacao', $garantia['erro']);
                }

                $pendentes = [];
            }
        }

        // C. aplica e confere
        foreach ($pendentes as [$arquivo, $usuario, $arg]) {
            $set = $sessao->executar($sudo.'setfacl -m '.escapeshellarg('u:'.$usuario.':---').' '.$arg, $timeout);

            if (! $set->ok()) {
                $erro = 'setfacl retornou '.var_export($set->rc, true).$set->complemento();

                if (stripos($set->erro, 'not supported') !== false) {
                    $erro .= ' | sistema de arquivos sem suporte a ACL — remontar com: mount -o remount,acl <ponto>';
                }

                if (stripos($set->erro, 'sudo') !== false || stripos($set->erro, 'password') !== false) {
                    $erro .= ' | sudo sem NOPASSWD para '.$alvo->usuario;
                }

                Log::warning("Sactus ACL #{$clienteId} | falha em {$arquivo} | {$erro}");
                $itens[$arquivo] = self::item($arquivo, $usuario, 'N', 'falha-setfacl', $erro);

                continue;
            }

            if ($this->jaAplicada($sessao, $sudo, $arg, $usuario, $timeout) !== true) {
                $itens[$arquivo] = self::item($arquivo, $usuario, 'N', 'nao-confirmada',
                    'setfacl executou sem erro mas o getfacl nao confirmou a entrada user:'.$usuario.':---');

                continue;
            }

            $itens[$arquivo] = $this->conferirDono($sessao, $arg, $usuario,
                self::item($arquivo, $usuario, 'S', $instalou ? 'aplicada-pos-instalacao' : 'aplicada'), $timeout);
        }

        // Na ordem das regras, para o detalhe ficar estável no painel
        $lista = array_values(array_filter(array_map(fn ($arquivo) => $itens[$arquivo] ?? null, array_keys($regras))));

        return new ResultadoRotina(
            status: $lista !== [] && count(array_filter($lista, fn (array $i): bool => $i['status'] === 'S')) === count($lista) ? 'S' : 'N',
            acao: ResultadoRotina::piorAcao($lista, self::PRIORIDADE),
            erro: self::erroFinal($lista),
            itens: $lista,
        );
    }

    /** true / false / null (não deu para verificar) */
    private function jaAplicada(SessaoSsh $sessao, string $sudo, string $arg, string $usuario, int $timeout): ?bool
    {
        $r = $sessao->executar($sudo.'getfacl -p '.$arg.' 2>/dev/null', $timeout);

        if (! $r->ok()) {
            return null;
        }

        return in_array('user:'.$usuario.':---', array_map('trim', preg_split('~\r\n|\n|\r~', $r->saida) ?: []), true);
    }

    /**
     * Se o arquivo pertence ao próprio usuário alvo, o kernel ignora a entrada ACL nomeada
     * (o dono usa a permissão de owner): a regra fica gravada mas não bloqueia nada.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function conferirDono(SessaoSsh $sessao, string $arg, string $usuario, array $item, int $timeout): array
    {
        $dono = $sessao->executar('stat -c %U '.$arg, $timeout);

        if ($dono->ok() && strcasecmp(trim($dono->saida), $usuario) === 0) {
            $item['aviso'] = 1;
            $item['erro'] = 'o arquivo pertence ao usuario '.$usuario.' — a entrada ACL nomeada nao tem efeito sobre o dono';
        }

        return $item;
    }

    /** @return array{ok: bool, instalou: bool, erro: string} */
    private function garantirSetfacl(SessaoSsh $sessao, string $sudo, int $clienteId, int $timeout): array
    {
        if ($sessao->executar('command -v setfacl >/dev/null 2>&1', $timeout)->ok()) {
            return ['ok' => true, 'instalou' => false, 'erro' => ''];
        }

        Log::info("Sactus ACL #{$clienteId} | setfacl ausente: instalando o pacote acl");
        $instalacao = $sessao->executar($sudo.'yum install -y acl', (int) config('walle.sactus.acl.instalar_segundos'));

        if (! $sessao->executar('command -v setfacl >/dev/null 2>&1', $timeout)->ok()) {
            return ['ok' => false, 'instalou' => false, 'erro' => 'setfacl indisponivel apos yum install (rc='.var_export($instalacao->rc, true).')'
                .$instalacao->complemento()
                .' | em CentOS 6.x confira se os .repo apontam para vault.centos.org'
                .' | se o yum estiver desatualizado, peça "Atualizar o sistema" desse cliente no painel do walle'];
        }

        return ['ok' => true, 'instalou' => true, 'erro' => ''];
    }

    /** @return array{arquivo: string, usuario: string, status: string, acao: string, erro: string, aviso: int} */
    private static function item(string $arquivo, string $usuario, string $status, string $acao, string $erro = ''): array
    {
        return ['arquivo' => $arquivo, 'usuario' => $usuario, 'status' => $status, 'acao' => $acao, 'erro' => $erro, 'aviso' => 0];
    }

    /**
     * O painel reconhece "aplicada porém sem efeito" pelo AVISO no começo do erro:
     * os avisos só vêm na frente quando não há falha.
     *
     * @param  list<array<string, mixed>>  $itens
     */
    private static function erroFinal(array $itens): string
    {
        $erros = [];
        $avisos = [];

        foreach ($itens as $item) {
            if ($item['erro'] !== '') {
                if ($item['aviso']) {
                    $avisos[] = $item['arquivo'].': '.$item['erro'];
                } else {
                    $erros[] = $item['arquivo'].': '.$item['erro'];
                }
            }
        }

        if ($erros !== []) {
            return implode(' | ', $erros).($avisos !== [] ? ' | AVISO '.implode(' | ', $avisos) : '');
        }

        return $avisos !== [] ? 'AVISO: '.implode(' | ', $avisos) : '';
    }
}
