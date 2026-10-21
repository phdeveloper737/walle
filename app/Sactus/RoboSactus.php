<?php

namespace App\Sactus;

use App\Models\CicloSactus;
use App\Sactus\Ssh\AlvoSsh;
use App\Sactus\Ssh\ConectorSsh;
use App\Sactus\Ssh\FalhaSsh;
use App\Support\PausaDeRobo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Robô do Sactus (substitui o tools/services/sactus.php). Cada ciclo, igual ao antigo:
 *   1. devolve para a fila os pedidos de edição presos e vê quem tem pedido pendente;
 *   2. lista os servidores no Help Desk e monta os alvos SSH de cada um;
 *   3. confere em paralelo quem responde (pré-checagem);
 *   4. em cada cliente, na mesma sessão SSH: aplica as edições do sactus.cfg, coleta o arquivo,
 *      aplica a ACL e atualiza o ERP (uma vez por cliente, no primeiro alvo que autenticar);
 *   5. grava o resultado no painel_sactus_cfg.
 *
 * Diferenças: o "yum -y update" virou ação manual do painel; o ciclo fica registrado no sactus_ciclos;
 * e um cliente nunca é processado por dois robôs ao mesmo tempo (ciclo e "aplicar agora").
 */
class RoboSactus
{
    private readonly PausaDeRobo $pausaDoRobo;

    public function __construct(
        private readonly ServidoresSactus $servidores,
        private readonly ConectorSsh $conector,
        private readonly Prechecagem $prechecagem,
        private readonly RotinaAcl $acl,
        private readonly RotinaErp $erp,
        private readonly EdicoesCfg $edicoes,
    ) {
        $this->pausaDoRobo = new PausaDeRobo('sactus:pausa');
    }

    public function ligado(): bool
    {
        return (bool) config('walle.sactus.ativo');
    }

    /** @return array{ate: Carbon, motivo: string, automatica: bool}|null */
    public function pausa(): ?array
    {
        return $this->pausaDoRobo->atual();
    }

    public function pausar(Carbon $ate, string $motivo = ''): void
    {
        $this->pausaDoRobo->pausar($ate, $motivo);
    }

    public function retomar(): void
    {
        $this->pausaDoRobo->retomar();
    }

    public function rodarCiclo(): CicloSactus
    {
        $ciclo = CicloSactus::create(['iniciado_em' => now()]);
        $limite = now()->addMinutes((int) config('walle.sactus.limite_ciclo_minutos'));

        try {
            $this->edicoes->destravar();
            $pendentes = $this->edicoes->clientesPendentes();
            $comErp = $this->erp->configurado();
            $planos = [];

            // Elegíveis e alvos
            foreach ($this->servidores->todos() as $cliente) {
                if (! $cliente->elegivel($comErp)) {
                    continue;
                }

                $alvos = $cliente->alvos($comErp);

                if ($alvos === []) {
                    $ciclo->sem_credenciais++;
                    $this->gravar($cliente->id, ['status' => 'N', 'erro' => 'Nenhum alvo SSH valido para o tipo '.$cliente->tipo()
                        .' (usuario/senha/host/porta ausentes ou host invalido).']);

                    continue;
                }

                $planos[$cliente->id] = [$cliente, $alvos];
            }

            $ciclo->elegiveis = count($planos) + $ciclo->sem_credenciais;
            $ciclo->save();

            // Pré-checagem de rede de todos os alvos de uma vez
            $todosOsAlvos = [];

            foreach ($planos as [, $alvos]) {
                array_push($todosOsAlvos, ...$alvos);
            }

            $vivos = $this->prechecar($todosOsAlvos);

            foreach ($planos as $id => [$cliente, $alvos]) {
                if (now()->greaterThan($limite)) {
                    $ciclo->erro = 'Ciclo interrompido no limite de '.config('walle.sactus.limite_ciclo_minutos').' minutos';
                    Log::warning('Sactus | '.$ciclo->erro);

                    break;
                }

                $resultado = $this->processarComTrava($cliente, $alvos, $vivos, isset($pendentes[$id]));
                $this->contar($ciclo, $resultado);

                if (($ciclo->coletados + $ciclo->falhas + $ciclo->offline) % 10 === 0) {
                    $ciclo->save();
                }
            }
        } catch (Throwable $erro) {
            report($erro);
            $ciclo->erro = mb_substr($erro->getMessage(), 0, 500);
        } finally {
            $ciclo->terminado_em = now();
            $ciclo->save();
        }

        return $ciclo;
    }

    /**
     * Processa um cliente só ("aplicar agora" do painel): edições pendentes, coleta, ACL e ERP.
     *
     * @return array{situacao: string, erro: string}
     */
    public function processarCliente(int $clienteId): array
    {
        $comErp = $this->erp->configurado();
        $cliente = collect($this->servidores->todos())->first(fn (ClienteSactus $c): bool => $c->id === $clienteId && $c->elegivel($comErp));

        if ($cliente === null) {
            return ['situacao' => 'fora', 'erro' => 'O cliente não está na coleta do Sactus (filtro do Help Desk).'];
        }

        $this->edicoes->destravar();
        $alvos = $cliente->alvos($comErp);

        if ($alvos === []) {
            $this->gravar($clienteId, ['status' => 'N', 'erro' => 'Nenhum alvo SSH valido para o tipo '.$cliente->tipo().'.']);

            return ['situacao' => 'falha', 'erro' => 'Nenhum alvo SSH válido no cadastro do Help Desk.'];
        }

        $resultado = $this->processarComTrava($cliente, $alvos, $this->prechecar($alvos), isset($this->edicoes->clientesPendentes()[$clienteId]));

        return ['situacao' => $resultado['situacao'], 'erro' => $resultado['erro']];
    }

    /**
     * @param  list<AlvoSsh>  $alvos
     * @return array<string, bool>
     */
    private function prechecar(array $alvos): array
    {
        $pares = [];

        foreach ($alvos as $alvo) {
            $pares[$alvo->chave()] = ['host' => $alvo->host, 'porta' => $alvo->porta];
        }

        return $pares === [] ? [] : $this->prechecagem->responde($pares);
    }

    /**
     * @param  list<AlvoSsh>  $alvos
     * @param  array<string, bool>  $vivos
     * @return array{situacao: string, erro: string, acl: ResultadoRotina|null, erp: ResultadoRotina|null, cfg: array{total: int, ok: int, erro: int, adiadas: int}|null}
     */
    private function processarComTrava(ClienteSactus $cliente, array $alvos, array $vivos, bool $cfgPendente): array
    {
        $trava = Cache::lock('sactus:cliente:'.$cliente->id, 3600);

        if (! $trava->get()) {
            return ['situacao' => 'ocupado', 'erro' => 'Outro robô já está neste cliente agora.', 'acl' => null, 'erp' => null, 'cfg' => null];
        }

        try {
            return $this->processar($cliente, $alvos, $vivos, $cfgPendente);
        } catch (Throwable $e) {
            // Um cliente com problema inesperado não derruba o ciclo dos outros
            report($e);
            $erro = 'erro inesperado: '.mb_substr($e->getMessage(), 0, 400);
            $this->gravar($cliente->id, ['status' => 'N', 'erro' => $erro]);

            return ['situacao' => 'falha', 'erro' => $erro, 'acl' => null, 'erp' => null, 'cfg' => null];
        } finally {
            $trava->release();
        }
    }

    /**
     * @param  list<AlvoSsh>  $alvos
     * @param  array<string, bool>  $vivos
     * @return array{situacao: string, erro: string, acl: ResultadoRotina|null, erp: ResultadoRotina|null, cfg: array{total: int, ok: int, erro: int, adiadas: int}|null}
     */
    private function processar(ClienteSactus $cliente, array $alvos, array $vivos, bool $cfgPendente): array
    {
        $id = $cliente->id;
        $vazio = ['acl' => null, 'erp' => null, 'cfg' => null];
        $primeiro = $alvos[0];
        $tentados = array_map(fn (AlvoSsh $a): string => $a->host.':'.$a->porta, $alvos);
        $alvos = array_values(array_filter($alvos, fn (AlvoSsh $a): bool => $vivos[$a->chave()] ?? false));

        if ($alvos === []) {
            $erro = 'Host inacessivel no pre-check (porta TCP): '.implode(', ', array_unique($tentados));
            $this->gravar($id, ['metodo' => $primeiro->metodo, 'host' => $primeiro->host, 'porta' => $primeiro->porta,
                'usuario' => $primeiro->usuario, 'status' => 'N', 'erro' => $erro]);

            return ['situacao' => 'offline', 'erro' => $erro, ...$vazio];
        }

        $comErp = $this->erp->configurado();
        $querAcl = $cliente->precisaAcl();
        $querErp = $cliente->precisaAtualizarErp($comErp);
        $erpVpn = $cliente->erpPorVpn($comErp);
        $inicio = hrtime(true);
        $erros = [];
        $tentativa = 0;
        $ultimo = $alvos[0];
        [$acl, $erp, $cfg] = [null, null, null];

        foreach ($alvos as $alvo) {
            $tentativa++;
            $ultimo = $alvo;

            try {
                $sessao = $this->conector->abrir($alvo);
            } catch (FalhaSsh $e) {
                $erros[] = "{$alvo->metodo} {$alvo->host}:{$alvo->porta} => {$e->getMessage()}";

                continue;
            }

            try {
                // Edições antes do cat: a coleta deste mesmo ciclo já traz o arquivo alterado
                if ($cfgPendente && $cfg === null) {
                    $cfg = $this->edicoes->processar($sessao, $alvo, $id);
                }

                $coleta = $sessao->executar('cat '.escapeshellarg((string) config('walle.sactus.cfg_caminho')), (int) config('walle.sactus.ssh.comando_segundos'));

                if ($querAcl && $acl === null) {
                    $acl = $this->acl->aplicar($sessao, $alvo, $id);
                }

                // Cliente da VPN: o ERP só roda pela VPN. Os alvos da VPN vêm primeiro, então chegar em
                // outro método quer dizer que a VPN falhou: registra e NÃO atualiza pelo IP externo.
                if ($querErp && $erp === null) {
                    $erp = $erpVpn && $alvo->metodo !== 'VPN' ? $this->erp->falhaVpn($alvo) : $this->erp->executar($sessao, $alvo, $id);
                }
            } finally {
                $sessao->fechar();
            }

            $falha = match (true) {
                $coleta->rc === null => 'comando nao concluiu dentro de '.config('walle.sactus.ssh.comando_segundos').'s'.$coleta->complemento(PHP_INT_MAX),
                $coleta->rc !== 0 => 'cat retornou '.$coleta->rc.$coleta->complemento(PHP_INT_MAX),
                trim($coleta->saida) === '' => config('walle.sactus.cfg_caminho').' vazio',
                default => null,
            };

            if ($falha !== null) {
                $erros[] = "{$alvo->metodo} {$alvo->host}:{$alvo->porta} => {$falha}";

                continue;
            }

            $this->gravar($id, [
                'metodo' => $alvo->metodo, 'host' => $alvo->host, 'porta' => $alvo->porta, 'usuario' => $alvo->usuario,
                'status' => 'S', 'erro' => '', 'conteudo' => $coleta->saida,
                'json' => (string) json_encode(ArquivoCfg::ler($coleta->saida), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'acl' => $acl, 'erp' => $erp, 'tentativas' => $tentativa,
                'duracao_ms' => (int) round((hrtime(true) - $inicio) / 1e6),
            ]);

            return ['situacao' => 'coletado', 'erro' => '', 'acl' => $acl, 'erp' => $erp, 'cfg' => $cfg];
        }

        $erro = implode(' | ', $erros);
        Log::info("Sactus #{$id} | falha final | {$erro}");
        $this->gravar($id, [
            'metodo' => $ultimo->metodo, 'host' => $ultimo->host, 'porta' => $ultimo->porta, 'usuario' => $ultimo->usuario,
            'status' => 'N', 'erro' => $erro, 'acl' => $acl, 'erp' => $erp, 'tentativas' => $tentativa,
            'duracao_ms' => (int) round((hrtime(true) - $inicio) / 1e6),
        ]);

        return ['situacao' => 'falha', 'erro' => $erro, 'acl' => $acl, 'erp' => $erp, 'cfg' => $cfg];
    }

    /** @param array{situacao: string, acl: ResultadoRotina|null, erp: ResultadoRotina|null, cfg: array{total: int, ok: int, erro: int, adiadas: int}|null} $r */
    private function contar(CicloSactus $ciclo, array $r): void
    {
        match ($r['situacao']) {
            'coletado' => $ciclo->coletados++,
            'offline' => $ciclo->offline++,
            'falha' => $ciclo->falhas++,
            default => null,
        };

        if ($r['acl'] !== null) {
            $r['acl']->ok() ? $ciclo->acl_ok++ : $ciclo->acl_falha++;
        }

        if ($r['erp'] !== null) {
            $r['erp']->ok() ? $ciclo->erp_ok++ : $ciclo->erp_falha++;
        }

        $ciclo->cfg_ok += $r['cfg']['ok'] ?? 0;
        $ciclo->cfg_falha += $r['cfg']['erro'] ?? 0;
    }

    /**
     * Grava (insere ou atualiza) o resultado de um cliente. ACL e ERP não avaliados neste ciclo ('X')
     * preservam o resultado anterior, como no gravarResultado() do antigo.
     *
     * @param  array<string, mixed>  $r
     */
    private function gravar(int $clienteId, array $r): void
    {
        /** @var ResultadoRotina|null $acl */
        $acl = $r['acl'] ?? null;
        /** @var ResultadoRotina|null $erp */
        $erp = $r['erp'] ?? null;
        $agora = now()->toDateTimeString();

        DB::statement(
            'INSERT INTO painel_sactus_cfg (fk_c_cliente, metodo, host, porta, usuario, status, erro, conteudo, json,
                acl_status, acl_acao, acl_erro, acl_json, acl_data, erp_status, erp_acao, erp_erro, erp_json, erp_data,
                tentativas, duracao_ms, data)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) AS novo
             ON DUPLICATE KEY UPDATE
                metodo = novo.metodo, host = novo.host, porta = novo.porta, usuario = novo.usuario,
                status = novo.status, erro = novo.erro, conteudo = novo.conteudo, json = novo.json,
                acl_status = IF(novo.acl_status = \'X\', painel_sactus_cfg.acl_status, novo.acl_status),
                acl_acao = IF(novo.acl_status = \'X\', painel_sactus_cfg.acl_acao, novo.acl_acao),
                acl_erro = IF(novo.acl_status = \'X\', painel_sactus_cfg.acl_erro, novo.acl_erro),
                acl_json = IF(novo.acl_status = \'X\', painel_sactus_cfg.acl_json, novo.acl_json),
                acl_data = IF(novo.acl_status = \'X\', painel_sactus_cfg.acl_data, novo.acl_data),
                erp_status = IF(novo.erp_status = \'X\', painel_sactus_cfg.erp_status, novo.erp_status),
                erp_acao = IF(novo.erp_status = \'X\', painel_sactus_cfg.erp_acao, novo.erp_acao),
                erp_erro = IF(novo.erp_status = \'X\', painel_sactus_cfg.erp_erro, novo.erp_erro),
                erp_json = IF(novo.erp_status = \'X\', painel_sactus_cfg.erp_json, novo.erp_json),
                erp_data = IF(novo.erp_status = \'X\', painel_sactus_cfg.erp_data, novo.erp_data),
                tentativas = novo.tentativas, duracao_ms = novo.duracao_ms, data = novo.data',
            [
                $clienteId, (string) ($r['metodo'] ?? ''), (string) ($r['host'] ?? ''), (int) ($r['porta'] ?? 0), (string) ($r['usuario'] ?? ''),
                ($r['status'] ?? 'N') === 'S' ? 'S' : 'N', mb_substr((string) ($r['erro'] ?? ''), 0, 500),
                (string) ($r['conteudo'] ?? ''), (string) ($r['json'] ?? ''),
                $acl->status ?? 'X', mb_substr($acl->acao ?? '', 0, 60), mb_substr($acl->erro ?? '', 0, 1000), $acl?->json() ?? '', $acl ? $agora : null,
                $erp->status ?? 'X', mb_substr($erp->acao ?? '', 0, 60), mb_substr($erp->erro ?? '', 0, 1000), $erp?->json() ?? '', $erp ? $agora : null,
                (int) ($r['tentativas'] ?? 0), (int) ($r['duracao_ms'] ?? 0), $agora,
            ],
        );
    }
}
