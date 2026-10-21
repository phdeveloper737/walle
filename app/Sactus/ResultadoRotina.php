<?php

namespace App\Sactus;

/**
 * Resumo de uma rotina (ACL ou ERP) num cliente, no formato das colunas acl_* / erp_* do painel_sactus_cfg.
 * status S = tudo certo; N = algo falhou. acao = o pior código entre os itens.
 */
final readonly class ResultadoRotina
{
    /** @param list<array<string, mixed>> $itens */
    public function __construct(
        public string $status,
        public string $acao,
        public string $erro,
        public array $itens,
    ) {}

    public function ok(): bool
    {
        return $this->status === 'S';
    }

    public function json(): string
    {
        return $this->itens === [] ? '' : (string) json_encode($this->itens, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * O pior código entre os itens, pela prioridade (menor peso = pior).
     *
     * @param  list<array<string, mixed>>  $itens
     * @param  array<string, int>  $prioridade
     */
    public static function piorAcao(array $itens, array $prioridade): string
    {
        $pior = '';
        $pesoPior = null;

        foreach ($itens as $item) {
            $peso = $prioridade[$item['acao']] ?? 99;

            if ($pesoPior === null || $peso < $pesoPior) {
                [$pesoPior, $pior] = [$peso, (string) $item['acao']];
            }
        }

        return $pior;
    }
}
