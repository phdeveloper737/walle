<?php

namespace App\Contingencias;

/** Contingência que o robô já encontrou autorizada na Sefaz (history_contingencia_auth). */
final readonly class Autorizada
{
    public function __construct(
        public string $chave,
        public string $referencia,
        public string $consultadaEm,
        public Contingencia $contingencia,
    ) {}

    /** @param array<string, mixed> $dados */
    public static function deArray(array $dados): self
    {
        return new self(
            chave: (string) $dados['chave'],
            referencia: (string) ($dados['referencia'] ?? ''),
            consultadaEm: (string) ($dados['consultada_em'] ?? ''),
            contingencia: Contingencia::deArray($dados['contingencia']),
        );
    }

    /** @return array<string, mixed> */
    public function paraArray(): array
    {
        return [
            'chave' => $this->chave,
            'referencia' => $this->referencia,
            'consultada_em' => $this->consultadaEm,
            'contingencia' => $this->contingencia->paraArray(),
        ];
    }

    public function consultadaEmFormatada(): string
    {
        return $this->consultadaEm !== '' ? date('d/m/Y H:i', (int) strtotime($this->consultadaEm)) : '';
    }
}
