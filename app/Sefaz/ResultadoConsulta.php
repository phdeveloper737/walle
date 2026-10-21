<?php

namespace App\Sefaz;

/** Resposta da Sefaz para uma chave. */
final readonly class ResultadoConsulta
{
    public function __construct(
        public string $chave,
        public SituacaoNfce $situacao,
        public ?string $cStat = null,
        public string $motivo = '',
        public ?string $protocolo = null,
        public ?string $recebidaEm = null,
    ) {}

    public static function erro(string $chave, string $motivo): self
    {
        return new self($chave, SituacaoNfce::Erro, motivo: $motivo);
    }

    public function autorizada(): bool
    {
        return $this->situacao === SituacaoNfce::Autorizada;
    }

    public function consumoIndevido(): bool
    {
        return $this->situacao === SituacaoNfce::ConsumoIndevido;
    }
}
