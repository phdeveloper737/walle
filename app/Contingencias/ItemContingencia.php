<?php

namespace App\Contingencias;

use App\Support\Texto;

/** Um item (produto) de uma venda em contingência. */
final readonly class ItemContingencia
{
    public function __construct(
        public string $codigoBarras,
        public string $idProduto,
        public string $nome,
        public string $unidade,
        public string $aliquota,
        public string $reducao,
        public string $aliquotaReduzida,
        public string $ncm,
        public string $cest,
        public string $cbenef,
        public string $cfop,
        public string $cst,
        public string $cstIbsCbs,
        public string $classTribIbs,
        public string $chave,
    ) {}

    /** @param array<string, mixed> $dados */
    public static function deArray(array $dados): self
    {
        $texto = fn (string $campo): string => Texto::utf8($dados[$campo] ?? '');

        return new self(
            codigoBarras: $texto('codigo_barras'),
            idProduto: $texto('id_produto'),
            nome: $texto('nome'),
            unidade: $texto('unidade'),
            aliquota: $texto('aliquota'),
            reducao: $texto('reducao'),
            aliquotaReduzida: $texto('aliquota_reduzida'),
            ncm: $texto('ncm'),
            cest: $texto('cest'),
            cbenef: $texto('cbenef'),
            cfop: $texto('cfop'),
            cst: $texto('cst'),
            cstIbsCbs: $texto('cst_ibscbs'),
            classTribIbs: $texto('classtrib_ibs'),
            chave: $texto('chave'),
        );
    }

    /** @return array<string, string> */
    public function paraArray(): array
    {
        return [
            'codigo_barras' => $this->codigoBarras,
            'id_produto' => $this->idProduto,
            'nome' => $this->nome,
            'unidade' => $this->unidade,
            'aliquota' => $this->aliquota,
            'reducao' => $this->reducao,
            'aliquota_reduzida' => $this->aliquotaReduzida,
            'ncm' => $this->ncm,
            'cest' => $this->cest,
            'cbenef' => $this->cbenef,
            'cfop' => $this->cfop,
            'cst' => $this->cst,
            'cst_ibscbs' => $this->cstIbsCbs,
            'classtrib_ibs' => $this->classTribIbs,
            'chave' => $this->chave,
        ];
    }

    /**
     * Inconsistências fiscais: as mesmas regras que pintavam a linha de vermelho no sistema antigo,
     * agora dizendo qual foi. CST e alíquotas comparados como número ("020" = "20").
     *
     * @return list<string>
     */
    public function inconsistencias(): array
    {
        $cst = $this->inteiro($this->cst);
        $semBeneficio = $this->cbenef === '' || strtoupper($this->cbenef) === 'NULL';
        $problemas = [];

        if ($cst === 20 || $cst === 40) {
            if ($semBeneficio) {
                $problemas[] = "CST {$this->cst} sem código de benefício (cBenef).";
            }

            if ($cst === 20) {
                $cheia = $this->inteiro($this->aliquota);
                $reduzida = $this->aliquotaReduzidaInteira();

                if ((float) $this->reducao == 0.0 || ($cheia !== null && $cheia === $reduzida)) {
                    $problemas[] = 'CST 20 sem redução: base de redução zerada ou alíquota reduzida igual à cheia.';
                }
            }

            return $problemas;
        }

        if ($cst === 0 && ! $semBeneficio) {
            $problemas[] = 'CST 00 com código de benefício (cBenef), que não deveria ter.';
        }

        if ($cst === 60 && $this->cfop !== '5405') {
            $problemas[] = 'CST 60 com CFOP '.($this->cfop !== '' ? $this->cfop : 'vazio').': o esperado é 5405.';
        }

        if (strtoupper($this->aliquotaReduzida) === 'F' && $this->cfop !== '5405') {
            $problemas[] = 'Alíquota F (substituição tributária) com CFOP diferente de 5405.';
        }

        return $problemas;
    }

    /** "18.000" => 18, "020" => 20; vazio ou texto => null */
    private function inteiro(string $valor): ?int
    {
        return is_numeric($valor) ? (int) floor((float) $valor) : null;
    }

    /** Sactus grava "T12" (tributado a 12%); Versatus grava "12.00". */
    private function aliquotaReduzidaInteira(): ?int
    {
        $valor = strtoupper($this->aliquotaReduzida);

        if (str_starts_with($valor, 'T')) {
            return $this->inteiro(substr($valor, 1));
        }

        return $this->inteiro($valor);
    }
}
