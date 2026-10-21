<?php

namespace App\Sactus;

use App\Models\SactusCfgEdicao;
use App\Sactus\Ssh\AlvoSsh;
use App\Sactus\Ssh\ResultadoComando;
use App\Sactus\Ssh\SessaoSsh;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Aplica no servidor do cliente os pedidos de edição de flag do sactus.cfg (processarEdicoesCfg do antigo).
 *
 * Por pedido: reserva → lê o arquivo → confere o md5 → calcula o texto novo → backup (conferido) →
 * sobe o conteúdo em base64 (conferido) → escreve com "cat >" (mantém inode, dono e ACL) →
 * RELÊ e compara byte a byte e chave a chave → CONCLUIDO, ou restaura o backup e marca ERRO.
 * Nunca marca CONCLUIDO pelo código de retorno da escrita: quem decide é a releitura.
 */
class EdicoesCfg
{
    /** Devolve para a fila os pedidos que ficaram presos em PROCESSANDO (worker morreu no meio). */
    public function destravar(): int
    {
        $minutos = (int) config('walle.sactus.cfg_edicoes.trava_minutos');

        return SactusCfgEdicao::query()
            ->where('status', 'PROCESSANDO')
            ->whereNotNull('iniciado_em')
            ->where('iniciado_em', '<', now()->subMinutes($minutos))
            ->update([
                'status' => 'PENDENTE',
                'lock_token' => null,
                'mensagem' => DB::raw("CONCAT('destravado apos {$minutos} min em PROCESSANDO | ', COALESCE(mensagem, ''))"),
            ]);
    }

    /** @return array<int, int> cliente => pedidos pendentes */
    public function clientesPendentes(): array
    {
        return SactusCfgEdicao::query()
            ->where('status', 'PENDENTE')
            ->groupBy('fk_c_cliente')
            ->selectRaw('fk_c_cliente, COUNT(*) AS n')
            ->pluck('n', 'fk_c_cliente')
            ->map(fn ($n): int => (int) $n)
            ->all();
    }

    /** @return array{total: int, ok: int, erro: int, adiadas: int} */
    public function processar(SessaoSsh $sessao, AlvoSsh $alvo, int $clienteId): array
    {
        $resumo = ['total' => 0, 'ok' => 0, 'erro' => 0, 'adiadas' => 0];
        $config = (array) config('walle.sactus.cfg_edicoes');
        $caminho = (string) config('walle.sactus.cfg_caminho');
        $sudo = $alvo->sudo();

        $pedidos = SactusCfgEdicao::query()
            ->where('fk_c_cliente', $clienteId)
            ->where('status', 'PENDENTE')
            ->orderBy('id')
            ->limit((int) $config['max_por_ciclo'])
            ->get();

        $resumo['total'] = $pedidos->count();

        if ($pedidos->isEmpty()) {
            return $resumo;
        }

        // Falha na leitura inicial é quase sempre passageira (servidor caiu, sudo recusou): volta para a fila
        $leitura = $this->ler($sessao, $sudo);

        if (! $leitura['ok']) {
            foreach ($pedidos as $pedido) {
                if ($this->reservar($pedido)) {
                    $resumo[$this->adiarOuErro($pedido, "nao foi possivel ler o {$caminho}: {$leitura['erro']}")]++;
                }
            }

            return $resumo;
        }

        $atual = $leitura['conteudo'];

        foreach ($pedidos as $pedido) {
            if (! $this->reservar($pedido)) {
                continue;
            }

            try {
                [$status, $mensagem, $novoAtual] = $this->aplicar($sessao, $sudo, $alvo, $pedido, $atual);
            } catch (Throwable $e) {
                [$status, $mensagem, $novoAtual] = ['ERRO', 'excecao ao processar: '.$e->getMessage(), $atual];
            }

            if ($status === 'ADIAR') {
                $resumo[$this->adiarOuErro($pedido, $mensagem)]++;

                continue;
            }

            $this->finalizar($pedido, $status, $mensagem);
            $resumo[$status === 'CONCLUIDO' ? 'ok' : 'erro']++;
            // A próxima edição parte do arquivo já alterado
            $atual = $novoAtual;

            Log::info("Sactus CFG #{$clienteId} | pedido {$pedido->id} {$pedido->tipo_edicao} {$pedido->flag} | {$status} | {$mensagem}");
        }

        return $resumo;
    }

    /** @return array{0: 'CONCLUIDO'|'ERRO'|'ADIAR', 1: string, 2: string} status, mensagem e o conteúdo atual depois */
    private function aplicar(SessaoSsh $sessao, string $sudo, AlvoSsh $alvo, SactusCfgEdicao $pedido, string $atual): array
    {
        $config = (array) config('walle.sactus.cfg_edicoes');
        $caminho = (string) config('walle.sactus.cfg_caminho');
        $tipo = strtoupper(trim($pedido->tipo_edicao));
        $flag = trim($pedido->flag);
        $valor = (string) $pedido->valor;

        // A linha vem do banco: valida de novo, nada garante que passou pelo formulário
        if (! in_array($tipo, ['ADICIONAR', 'REMOVER'], true)) {
            return ['ERRO', 'tipo de edicao invalido: '.$tipo, $atual];
        }

        if (! EdicaoNoTexto::nomeValido($flag)) {
            return ['ERRO', 'nome de flag invalido: '.mb_substr($flag, 0, 80), $atual];
        }

        if ($tipo === 'ADICIONAR' && ! EdicaoNoTexto::valorValido($valor)) {
            return ['ERRO', 'valor invalido: quebra de linha, caractere de controle ou acima de 1000 bytes', $atual];
        }

        if (strlen($atual) > (int) $config['max_bytes']) {
            return ['ERRO', "{$caminho} tem ".strlen($atual).' bytes, acima do limite de '.(int) $config['max_bytes'], $atual];
        }

        // A cópia em memória precisa ser fiel ao arquivo, senão escrever a partir dela corromperia o cfg
        $md5Arquivo = $this->md5($sessao, $sudo, $caminho);

        if ($md5Arquivo === '') {
            return ['ADIAR', "nao foi possivel calcular o md5sum de {$caminho}", $atual];
        }

        if ($md5Arquivo !== md5($atual)) {
            return ['ERRO', "a leitura do {$caminho} nao confere com o md5sum do servidor — nada foi alterado", $atual];
        }

        $edicao = EdicaoNoTexto::aplicar($atual, $tipo, $flag, $valor);

        if (! $edicao['ok']) {
            return ['ERRO', $edicao['erro'], $atual];
        }

        if (! $edicao['mudou']) {
            return ['CONCLUIDO', $edicao['msg'].' | arquivo intacto', $atual];
        }

        $novo = $edicao['novo'];
        $timeout = (int) $config['comando_segundos'];

        // Backup antes de escrever, conferido: sem ele íntegro não há rollback e a escrita não acontece
        $backup = rtrim((string) $config['backup_dir'], '/').'/sactus.cfg_'.now()->format('Ymd_His').'_e'.$pedido->id.'.bkp';
        $copia = $sessao->executar($sudo.'cp -p '.escapeshellarg($caminho).' '.escapeshellarg($backup), $timeout);

        if (! $copia->ok()) {
            return ['ERRO', "falha ao gerar o backup em {$backup} (rc=".var_export($copia->rc, true).')'.$copia->complemento().' — nada foi alterado', $atual];
        }

        if ($this->md5($sessao, $sudo, $backup) !== $md5Arquivo) {
            return ['ERRO', "o backup {$backup} nao confere com o arquivo original — nada foi alterado", $atual];
        }

        $subida = $this->subirTemporario($sessao, $novo);

        if (! $subida['ok']) {
            return ['ERRO', $subida['erro'].' | backup em '.$backup, $atual];
        }

        $escrita = $this->escreverPorCima($sessao, $sudo, $subida['arquivo'], $caminho);

        // Relê e valida: byte a byte e, depois, nenhuma outra chave pode ter mudado
        $releitura = $this->ler($sessao, $sudo);
        $aplicado = $releitura['ok'] && $releitura['conteudo'] === $novo;
        $motivo = match (true) {
            ! $releitura['ok'] => 'nao foi possivel reler o arquivo apos a escrita: '.$releitura['erro'],
            ! $aplicado => 'o conteudo lido apos a escrita nao confere com o esperado',
            EdicaoNoTexto::chavesSemFlag($atual, $flag) !== EdicaoNoTexto::chavesSemFlag($releitura['conteudo'], $flag) => 'a edicao alterou outras chaves do arquivo',
            default => '',
        };
        $aplicado = $aplicado && $motivo === '';

        if (! $escrita->ok() && ! $aplicado) {
            $motivo = "falha ao escrever em {$caminho} (rc=".var_export($escrita->rc, true).')'.$escrita->complemento().($motivo !== '' ? ' | '.$motivo : '');

            if (stripos($escrita->erro, 'sudo') !== false || stripos($escrita->erro, 'password') !== false) {
                $motivo .= ' | sudo sem NOPASSWD para '.$alvo->usuario;
            }

            if (stripos($escrita->erro, 'permission') !== false) {
                $motivo .= " | sem permissao de escrita — confira a ACL aplicada em {$caminho}";
            }
        }

        $tmp = (string) $config['tmp'];
        $sessao->executar('rm -f '.escapeshellarg($tmp.'.b64').' '.escapeshellarg($tmp.'.new'), $timeout);

        if ($aplicado) {
            return ['CONCLUIDO', $edicao['msg'].' | backup em '.$backup, $releitura['conteudo']];
        }

        // Rollback
        $volta = $this->escreverPorCima($sessao, $sudo, $backup, $caminho);
        $conferencia = $this->ler($sessao, $sudo);

        $rollback = $conferencia['ok'] && $conferencia['conteudo'] === $atual
            ? 'arquivo restaurado a partir de '.$backup
            : 'ATENCAO: A RESTAURACAO FALHOU (rc='.var_export($volta->rc, true).") — o backup integro esta em {$backup} no servidor do cliente";

        if (! str_starts_with($rollback, 'arquivo restaurado')) {
            Log::error("Sactus CFG #{$pedido->fk_c_cliente} | ROLLBACK FALHOU no pedido {$pedido->id} | backup {$backup}");
        }

        return ['ERRO', $motivo.' | '.$rollback, $atual];
    }

    /** Reserva o pedido para esta execução: dois robôs ao mesmo tempo nunca aplicam o mesmo pedido. */
    private function reservar(SactusCfgEdicao $pedido): bool
    {
        $reservou = SactusCfgEdicao::query()
            ->whereKey($pedido->id)
            ->where('status', 'PENDENTE')
            ->update([
                'status' => 'PROCESSANDO',
                'lock_token' => Str::random(32),
                'iniciado_em' => now(),
                'tentativas' => DB::raw('tentativas + 1'),
            ]) === 1;

        if ($reservou) {
            $pedido->refresh();
        }

        return $reservou;
    }

    /** Falha passageira: volta para a fila até o limite de tentativas, depois vira ERRO. Devolve a chave do resumo. */
    private function adiarOuErro(SactusCfgEdicao $pedido, string $mensagem): string
    {
        $maximo = (int) config('walle.sactus.cfg_edicoes.max_tentativas');
        $status = $pedido->tentativas >= $maximo ? 'ERRO' : 'PENDENTE';

        $this->finalizar($pedido, $status, "{$mensagem} (tentativa {$pedido->tentativas}/{$maximo})");

        return $status === 'ERRO' ? 'erro' : 'adiadas';
    }

    private function finalizar(SactusCfgEdicao $pedido, string $status, string $mensagem): void
    {
        $pedido->forceFill([
            'status' => $status,
            'mensagem' => mb_substr(trim($mensagem), 0, 2000),
            'processado_em' => $status === 'PENDENTE' ? null : now(),
            'lock_token' => null,
        ])->save();
    }

    /** @return array{ok: bool, conteudo: string, erro: string} */
    private function ler(SessaoSsh $sessao, string $sudo): array
    {
        $caminho = (string) config('walle.sactus.cfg_caminho');
        $r = $sessao->executar($sudo.'cat '.escapeshellarg($caminho), (int) config('walle.sactus.cfg_edicoes.comando_segundos'));

        return match (true) {
            $r->rc === null => ['ok' => false, 'conteudo' => '', 'erro' => "sem resposta ao ler {$caminho}".$r->complemento()],
            $r->rc !== 0 => ['ok' => false, 'conteudo' => '', 'erro' => "cat {$caminho} retornou {$r->rc}".$r->complemento()],
            default => ['ok' => true, 'conteudo' => $r->saida, 'erro' => ''],
        };
    }

    private function md5(SessaoSsh $sessao, string $sudo, string $caminho): string
    {
        $r = $sessao->executar($sudo.'md5sum '.escapeshellarg($caminho), (int) config('walle.sactus.cfg_edicoes.comando_segundos'));

        return $r->ok() && preg_match('~^([0-9a-f]{32})\b~i', trim($r->saida), $m) ? strtolower($m[1]) : '';
    }

    /**
     * Sobe o conteúdo para um temporário e confere o md5 do que chegou. Vai em base64, em pedaços:
     * nenhum caractere do valor passa pelo shell e o comando não estoura o tamanho de linha.
     *
     * @return array{ok: bool, arquivo: string, erro: string}
     */
    private function subirTemporario(SessaoSsh $sessao, string $conteudo): array
    {
        $config = (array) config('walle.sactus.cfg_edicoes');
        $caminho = (string) config('walle.sactus.cfg_caminho');
        $timeout = (int) $config['comando_segundos'];
        [$b64, $novo] = [$config['tmp'].'.b64', $config['tmp'].'.new'];

        $sessao->executar('rm -f '.escapeshellarg($b64).' '.escapeshellarg($novo), $timeout);

        foreach (str_split(base64_encode($conteudo), (int) $config['pedaco']) as $parte) {
            $r = $sessao->executar('printf %s '.escapeshellarg($parte).' >> '.escapeshellarg($b64), $timeout);

            if (! $r->ok()) {
                return ['ok' => false, 'arquivo' => $novo, 'erro' => 'falha ao enviar o conteudo (rc='.var_export($r->rc, true).')'.$r->complemento()];
            }
        }

        $r = $sessao->executar('base64 -d '.escapeshellarg($b64).' > '.escapeshellarg($novo), $timeout);

        if (! $r->ok()) {
            return ['ok' => false, 'arquivo' => $novo, 'erro' => 'falha ao decodificar o conteudo no servidor (rc='.var_export($r->rc, true).')'.$r->complemento()];
        }

        $md5 = $this->md5($sessao, '', $novo);

        if ($md5 === '' || $md5 !== md5($conteudo)) {
            return ['ok' => false, 'arquivo' => $novo, 'erro' => 'o conteudo chegou diferente no servidor (md5 '.($md5 === '' ? 'indisponivel' : $md5)
                .' != '.md5($conteudo).") — nada foi escrito no {$caminho}"];
        }

        return ['ok' => true, 'arquivo' => $novo, 'erro' => ''];
    }

    /**
     * "cat origem > destino" (nunca mv/cp: o mv troca o inode e derruba a ACL aplicada).
     * Com sudo, o redirecionamento precisa acontecer dentro dele.
     */
    private function escreverPorCima(SessaoSsh $sessao, string $sudo, string $origem, string $destino): ResultadoComando
    {
        $interno = 'cat '.escapeshellarg($origem).' > '.escapeshellarg($destino);

        return $sessao->executar($sudo === '' ? $interno : $sudo.'sh -c '.escapeshellarg($interno), (int) config('walle.sactus.cfg_edicoes.comando_segundos'));
    }
}
