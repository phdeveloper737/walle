<?php

namespace App\Sactus;

use App\Jobs\ExecutarAcaoSactus;
use App\Models\AcaoSactus;
use App\Models\Auditoria;
use App\Models\Usuario;
use App\Sactus\Ssh\ConectorSsh;
use App\Sactus\Ssh\FalhaSsh;
use InvalidArgumentException;

/**
 * Ações manuais no servidor de um cliente, pedidas por um admin no painel e executadas na fila
 * (no antigo o "yum -y update" rodava sozinho dentro da rotina de ACL).
 */
class AcoesSactus
{
    public function __construct(
        private readonly ServidoresSactus $servidores,
        private readonly ConectorSsh $conector,
        private readonly Prechecagem $prechecagem,
        private readonly RotinaErp $erp,
    ) {}

    /** Registra o pedido (com auditoria) e põe na fila. Uma ação aberta por cliente. */
    public function pedir(int $clienteId, string $acao, Usuario $usuario, ?string $ip): AcaoSactus
    {
        if (! array_key_exists($acao, AcaoSactus::ACOES)) {
            throw new InvalidArgumentException('Ação desconhecida.');
        }

        $aberta = AcaoSactus::query()->where('fk_c_cliente', $clienteId)->whereIn('status', ['PENDENTE', 'PROCESSANDO'])->first();

        if ($aberta !== null) {
            throw new InvalidArgumentException("Já tem uma ação em andamento neste cliente (nº {$aberta->id}).");
        }

        $pedido = AcaoSactus::create([
            'fk_c_cliente' => $clienteId,
            'acao' => $acao,
            'solicitante_id' => $usuario->id,
            'solicitante' => $usuario->name,
            'ip' => $ip,
            'criado_em' => now(),
        ]);

        Auditoria::registrar('sactus.acao.pedir', $clienteId, ['acao' => $acao, 'pedido' => $pedido->id]);
        ExecutarAcaoSactus::dispatch($pedido->id);

        return $pedido;
    }

    public function executar(AcaoSactus $pedido): void
    {
        $reservou = AcaoSactus::query()->whereKey($pedido->id)->where('status', 'PENDENTE')
            ->update(['status' => 'PROCESSANDO', 'iniciado_em' => now()]) === 1;

        if (! $reservou) {
            return;
        }

        $config = (array) config('walle.sactus.acoes.'.$pedido->acao);
        $comErp = $this->erp->configurado();
        $cliente = collect($this->servidores->todos())->first(fn (ClienteSactus $c): bool => $c->id === $pedido->fk_c_cliente);

        if ($config === [] || $cliente === null) {
            $this->terminar($pedido, 'ERRO', null, '', $config === [] ? 'Ação sem comando configurado.' : 'O cliente não está na coleta do Sactus (filtro do Help Desk).');

            return;
        }

        $alvos = $cliente->alvos($comErp);
        $pares = [];

        foreach ($alvos as $alvo) {
            $pares[$alvo->chave()] = ['host' => $alvo->host, 'porta' => $alvo->porta];
        }

        $vivos = $pares === [] ? [] : $this->prechecagem->responde($pares);
        $erros = [];

        foreach ($alvos as $alvo) {
            if (! ($vivos[$alvo->chave()] ?? false)) {
                $erros[] = "{$alvo->host}:{$alvo->porta} não respondeu";

                continue;
            }

            try {
                $sessao = $this->conector->abrir($alvo);
            } catch (FalhaSsh $e) {
                $erros[] = "{$alvo->descricao()} => {$e->getMessage()}";

                continue;
            }

            try {
                $r = $sessao->executar($alvo->sudo().$config['comando'], (int) $config['segundos']);
            } finally {
                $sessao->fechar();
            }

            $saida = trim($r->saida);
            $pedido->alvo = $alvo->descricao();

            $this->terminar($pedido, $r->ok() ? 'CONCLUIDO' : 'ERRO', $r->rc,
                strlen($saida) > 8000 ? '...(truncado)...'.substr($saida, -8000) : $saida,
                match (true) {
                    $r->rc === null => "não terminou em {$config['segundos']} s".$r->complemento(),
                    $r->rc !== 0 => "retornou {$r->rc}".$r->complemento(500),
                    default => '',
                });

            return;
        }

        $this->terminar($pedido, 'ERRO', null, '', $alvos === [] ? 'Nenhum alvo SSH válido no cadastro do Help Desk.' : implode(' | ', $erros));
    }

    private function terminar(AcaoSactus $pedido, string $status, ?int $rc, string $saida, string $erro): void
    {
        $pedido->forceFill([
            'status' => $status,
            'rc' => $rc,
            'saida' => $saida,
            'erro' => $erro === '' ? null : mb_substr($erro, 0, 1000),
            'processado_em' => now(),
        ])->save();
    }
}
