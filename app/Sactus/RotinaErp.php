<?php

namespace App\Sactus;

use App\Sactus\Ssh\AlvoSsh;
use App\Sactus\Ssh\SessaoSsh;
use Illuminate\Support\Facades\Log;

/**
 * Atualização do ERP no servidor do cliente (atualizar_erp = 'S' no Help Desk), igual ao antigo:
 * rsync de /var/sistema a partir do 220, chown e chmod, em ordem, parando no primeiro que falhar.
 * Roda em todo ciclo enquanto a coluna estiver em 'S' (quem liga e desliga é o Help Desk).
 *
 * Diferença: a senha do root do 220 sai do código e vem do .env (SACTUS_ERP_SENHA). Sem ela a rotina não roda.
 */
class RotinaErp
{
    public const PRIORIDADE = [
        'vpn-inacessivel' => 1,
        'sem-resposta' => 1,
        'comando-inexistente' => 2,
        'falha' => 3,
        'nao-executado' => 4,
        'executado' => 5,
    ];

    public const ROTULOS = [
        'executado' => 'Executado',
        'falha' => 'Falhou',
        'comando-inexistente' => 'Comando não existe no servidor',
        'sem-resposta' => 'Não terminou no prazo',
        'nao-executado' => 'Não executado (o anterior falhou)',
        'vpn-inacessivel' => 'VPN não conectou',
    ];

    /**
     * Comandos na ordem. Vazio quando a senha do 220 não está configurada.
     *
     * @return list<string>
     */
    public function comandos(): array
    {
        $erp = (array) config('walle.sactus.erp');

        if (trim((string) ($erp['senha'] ?? '')) === '') {
            return [];
        }

        return [
            // A origem vai sem aspas de propósito: o * é expandido do lado do 220 (igual ao antigo)
            'sshpass -p'.escapeshellarg((string) $erp['senha']).' rsync -Cravzp -e '.escapeshellarg('ssh -p '.(int) $erp['porta_origem'])
                .' '.$erp['origem'].' '.escapeshellarg((string) $erp['destino']),
            ...array_values((array) $erp['depois']),
        ];
    }

    public function configurado(): bool
    {
        return $this->comandos() !== [];
    }

    public function executar(SessaoSsh $sessao, AlvoSsh $alvo, int $clienteId): ResultadoRotina
    {
        $timeout = (int) config('walle.sactus.erp.comando_segundos');
        $saidaMax = (int) config('walle.sactus.erp.saida_max');
        $lista = [];
        $abortou = false;

        foreach ($this->comandos() as $i => $comando) {
            $item = ['ordem' => $i + 1, 'comando' => self::mascarar($comando), 'rc' => null, 'status' => 'N', 'acao' => '', 'erro' => '', 'saida' => '', 'duracao_ms' => 0];

            if ($abortou) {
                $lista[] = [...$item, 'acao' => 'nao-executado', 'erro' => 'nao executado — um comando anterior falhou'];

                continue;
            }

            $inicio = hrtime(true);
            $r = $sessao->executar($alvo->sudo().$comando, $timeout);
            $item['duracao_ms'] = (int) round((hrtime(true) - $inicio) / 1e6);
            $item['rc'] = $r->rc;

            // Só a cauda: o rsync -v de /var/sistema gera muitas linhas
            $saida = trim($r->saida);
            $item['saida'] = strlen($saida) > $saidaMax ? '...(truncado)...'.substr($saida, -$saidaMax) : $saida;

            if ($r->rc === null) {
                $abortou = true;
                $lista[] = [...$item, 'acao' => 'sem-resposta', 'erro' => "comando nao concluiu dentro de {$timeout}s".$r->complemento()];

                continue;
            }

            if ($r->rc !== 0) {
                $erro = 'retornou '.$r->rc.$r->complemento(300);

                if ($r->rc === 127) {
                    $erro .= ' | binario ausente no servidor (verifique sshpass/rsync)';
                }

                if (stripos($r->erro, 'sudo') !== false || stripos($r->erro, 'password') !== false) {
                    $erro .= ' | sudo sem NOPASSWD para '.$alvo->usuario;
                }

                $abortou = true;
                Log::warning("Sactus ERP #{$clienteId} | falha no comando {$item['ordem']} | ".self::mascarar($erro));
                $lista[] = [...$item, 'acao' => $r->rc === 127 ? 'comando-inexistente' : 'falha', 'erro' => self::mascarar($erro)];

                continue;
            }

            $lista[] = [...$item, 'status' => 'S', 'acao' => 'executado'];
        }

        $erros = array_map(fn (array $i): string => '['.$i['ordem'].'] '.$i['erro'], array_filter($lista, fn (array $i): bool => $i['erro'] !== ''));

        return new ResultadoRotina(
            status: $lista !== [] && count(array_filter($lista, fn (array $i): bool => $i['status'] === 'S')) === count($lista) ? 'S' : 'N',
            acao: ResultadoRotina::piorAcao($lista, self::PRIORIDADE),
            erro: implode(' | ', $erros),
            itens: $lista,
        );
    }

    /** Cliente da VPN cujo IP da VPN não conectou: a atualização NÃO roda pelo IP externo. */
    public function falhaVpn(AlvoSsh $alvo): ResultadoRotina
    {
        return new ResultadoRotina('N', 'vpn-inacessivel',
            'atualizacao do ERP exige o IP da VPN ('.config('walle.sactus.erp.vpn_prefixo').'x), que nao conectou neste ciclo'
            ." — nao executada por {$alvo->metodo} {$alvo->host}", []);
    }

    /** Tira senhas de uma linha de comando antes de gravar em log ou banco ("sshpass -p'x'", "--password=x"...). */
    public static function mascarar(string $comando): string
    {
        $c = (string) preg_replace("~(-p)\s*'[^']*'~", "$1'***'", $comando);
        $c = (string) preg_replace('~(-p)\s*"[^"]*"~', '$1"***"', $c);
        $c = (string) preg_replace('~(sshpass\s+-p)\s+(\S+)~', '$1 ***', $c);

        return (string) preg_replace('~(--password[=\s])\S+~', '$1***', $c);
    }
}
