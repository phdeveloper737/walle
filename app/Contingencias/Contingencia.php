<?php

namespace App\Contingencias;

use App\Support\Texto;
use Illuminate\Support\Str;

/** Uma venda em contingência (NFC-e que não foi autorizada) com os itens dela. */
final readonly class Contingencia
{
    public const SACTUS = 'SACTUS';

    public const VERSATUS = 'VERSATUS';

    /** @param list<ItemContingencia> $itens */
    public function __construct(
        public string $software,
        public string $loja,
        public string $pdv,
        public string $cupom,
        public string $emitidaEm,
        public string $codigoMotivo,
        public string $motivo,
        public string $chave,
        public array $itens,
    ) {}

    /** @param array<string, mixed> $dados */
    public static function deArray(array $dados): self
    {
        $texto = fn (string $campo): string => Texto::utf8($dados[$campo] ?? '');

        return new self(
            software: $texto('software'),
            loja: $texto('loja'),
            pdv: $texto('pdv'),
            cupom: $texto('cupom'),
            emitidaEm: $texto('emitida_em'),
            codigoMotivo: $texto('codigo_motivo'),
            motivo: $texto('motivo'),
            chave: $texto('chave'),
            itens: array_map(ItemContingencia::deArray(...), $dados['itens'] ?? []),
        );
    }

    /** @return array<string, mixed> */
    public function paraArray(): array
    {
        return [
            'software' => $this->software,
            'loja' => $this->loja,
            'pdv' => $this->pdv,
            'cupom' => $this->cupom,
            'emitida_em' => $this->emitidaEm,
            'codigo_motivo' => $this->codigoMotivo,
            'motivo' => $this->motivo,
            'chave' => $this->chave,
            'itens' => array_map(fn (ItemContingencia $item): array => $item->paraArray(), $this->itens),
        ];
    }

    /** Loja + PDV + cupom: identifica a venda (o sistema antigo também juntava por isso). */
    public function identificador(): string
    {
        return "{$this->loja}_{$this->pdv}_{$this->cupom}";
    }

    /** Chave de 44 dígitos da NFC-e: a do cabeçalho ou, se não tiver, a do primeiro item. */
    public function chaveNfce(): string
    {
        foreach ([$this->chave, $this->itens[0]->chave ?? ''] as $chave) {
            $digitos = preg_replace('/\D/', '', $chave) ?? '';

            if (strlen($digitos) === 44) {
                return $digitos;
            }
        }

        return '';
    }

    public function data(): string
    {
        return strlen($this->emitidaEm) >= 10
            ? substr($this->emitidaEm, 8, 2).'/'.substr($this->emitidaEm, 5, 2).'/'.substr($this->emitidaEm, 0, 4)
            : '';
    }

    public function hora(): string
    {
        return substr($this->emitidaEm, 11, 8);
    }

    public function itensComInconsistencia(): int
    {
        return count(array_filter($this->itens, fn (ItemContingencia $item): bool => $item->inconsistencias() !== []));
    }

    /** Filtro da tela: cupom, loja, PDV, motivo, chave e qualquer campo dos itens. */
    public function corresponde(string $termo): bool
    {
        $termo = Str::lower(Str::ascii(trim($termo)));

        if ($termo === '') {
            return true;
        }

        $texto = implode(' ', [
            $this->software, "loja {$this->loja}", "pdv {$this->pdv}", "cupom {$this->cupom}", $this->cupom,
            $this->codigoMotivo, $this->motivo, $this->chave,
        ]);

        foreach ($this->itens as $item) {
            $texto .= ' '.implode(' ', $item->paraArray());
        }

        return str_contains(Str::lower(Str::ascii($texto)), $termo);
    }
}
