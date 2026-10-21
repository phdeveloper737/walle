<?php

namespace App\Sactus;

use App\Contingencias\ListaDeClientes;
use App\Models\PainelSactusCfg;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/**
 * Dados do painel do Sactus a partir do painel_sactus_cfg (substitui a consulta do sactus.dash.php).
 * Diferença: o antigo escondia os clientes cuja coleta falhou (só mostrava quem tinha json); aqui aparecem todos.
 */
class PainelSactus
{
    public const FILTROS = [
        'todos' => 'Todos',
        'coletados' => 'Coletados',
        'falhas' => 'Com falha',
        'obsoletos' => 'Desatualizados',
        'acl_ok' => 'ACL aplicada',
        'acl_aviso' => 'ACL sem efeito',
        'acl_falha' => 'ACL falhou',
        'erp_ok' => 'ERP atualizado',
        'erp_falha' => 'ERP falhou',
    ];

    /**
     * Uma linha por cliente, sem o conteúdo do arquivo (que só vai para o detalhe).
     *
     * @return list<array{id: int, nome: string, cnpj: string, coletado: bool, obsoleto: bool, metodo: string, erro: string, data: Carbon|null, acl: string, erp: string, flags: list<string>}>
     */
    public function linhas(): array
    {
        $clientes = $this->clientesDoHelp();
        $limite = now()->subMinutes((int) config('walle.sactus.obsoleto_minutos'));

        return PainelSactusCfg::query()
            ->get(['fk_c_cliente', 'metodo', 'status', 'erro', 'json', 'acl_status', 'acl_erro', 'erp_status', 'data'])
            ->map(fn (PainelSactusCfg $p): array => [
                'id' => $p->fk_c_cliente,
                'nome' => $clientes[$p->fk_c_cliente]['nome'] ?? 'Cliente #'.$p->fk_c_cliente,
                'cnpj' => $clientes[$p->fk_c_cliente]['cnpj'] ?? '',
                'coletado' => $p->coletado(),
                'obsoleto' => $p->data !== null && $p->data->lt($limite),
                'metodo' => (string) $p->metodo,
                'erro' => (string) $p->erro,
                'data' => $p->data,
                'acl' => self::estadoAcl($p),
                'erp' => self::estadoErp($p),
                'flags' => $p->coletado() ? array_keys($p->flags()) : [],
            ])
            ->sortBy('nome', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $linhas
     * @return array<string, int>
     */
    public static function kpis(array $linhas): array
    {
        $kpis = array_fill_keys(array_keys(self::FILTROS), 0);
        $kpis['todos'] = count($linhas);

        foreach ($linhas as $l) {
            foreach (array_keys(self::FILTROS) as $filtro) {
                if ($filtro !== 'todos' && self::passa($l, $filtro)) {
                    $kpis[$filtro]++;
                }
            }
        }

        return $kpis;
    }

    /** @param array<string, mixed> $linha */
    public static function passa(array $linha, string $filtro): bool
    {
        return match ($filtro) {
            'coletados' => $linha['coletado'],
            'falhas' => ! $linha['coletado'],
            'obsoletos' => $linha['obsoleto'],
            'acl_ok' => $linha['acl'] === 'ok',
            'acl_aviso' => $linha['acl'] === 'aviso',
            'acl_falha' => $linha['acl'] === 'falha',
            'erp_ok' => $linha['erp'] === 'ok',
            'erp_falha' => $linha['erp'] === 'falha',
            default => true,
        };
    }

    /** na = não avaliada (cliente sem service_acl) · ok · aviso (aplicada mas sem efeito) · falha */
    public static function estadoAcl(PainelSactusCfg $p): string
    {
        return match ($p->acl_status) {
            'S' => stripos($p->acl_erro, 'AVISO') === 0 ? 'aviso' : 'ok',
            'N' => 'falha',
            default => 'na',
        };
    }

    public static function estadoErp(PainelSactusCfg $p): string
    {
        return match ($p->erp_status) {
            'S' => 'ok',
            'N' => 'falha',
            default => 'na',
        };
    }

    /**
     * Nomes de todas as flags coletadas (para o verificador sugerir).
     *
     * @param  list<array<string, mixed>>  $linhas
     * @return list<string>
     */
    public static function nomesDeFlags(array $linhas): array
    {
        $nomes = array_unique(array_merge(...array_map(fn (array $l): array => $l['flags'], $linhas ?: [['flags' => []]])));
        natcasesort($nomes);

        return array_values($nomes);
    }

    /** @return array<int, array{nome: string, cnpj: string}> */
    private function clientesDoHelp(): array
    {
        try {
            $clientes = [];

            foreach (app(ListaDeClientes::class)->todos() as $c) {
                $clientes[$c->id] = ['nome' => $c->razaoSocial, 'cnpj' => $c->cnpj];
            }

            return $clientes;
        } catch (QueryException $e) {
            report($e);

            return [];
        }
    }
}
