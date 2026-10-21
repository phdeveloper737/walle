<?php

namespace App\Contingencias;

/** O que a busca encontrou para um cliente: pendentes, já autorizadas e avisos. */
final readonly class ResultadoContingencias
{
    /**
     * @param  list<Contingencia>  $pendentes
     * @param  list<Autorizada>  $autorizadas
     */
    public function __construct(
        public array $pendentes,
        public array $autorizadas,
        public int $totalNoPdvSactus,
        public ?string $avisoSactus,
        public string $consultadoEm,
    ) {}

    /** @param array<string, mixed> $dados */
    public static function deArray(array $dados): self
    {
        return new self(
            pendentes: array_map(Contingencia::deArray(...), $dados['pendentes']),
            autorizadas: array_map(Autorizada::deArray(...), $dados['autorizadas']),
            totalNoPdvSactus: (int) $dados['total_no_pdv_sactus'],
            avisoSactus: $dados['aviso_sactus'],
            consultadoEm: (string) $dados['consultado_em'],
        );
    }

    /** @return array<string, mixed> */
    public function paraArray(): array
    {
        return [
            'pendentes' => array_map(fn (Contingencia $c): array => $c->paraArray(), $this->pendentes),
            'autorizadas' => array_map(fn (Autorizada $a): array => $a->paraArray(), $this->autorizadas),
            'total_no_pdv_sactus' => $this->totalNoPdvSactus,
            'aviso_sactus' => $this->avisoSactus,
            'consultado_em' => $this->consultadoEm,
        ];
    }

    public function totalDo(string $software): int
    {
        return count(array_filter($this->pendentes, fn (Contingencia $c): bool => $c->software === $software));
    }

    /** "SACTUS e VERSATUS", "SACTUS", "VERSATUS" ou "" (como o sistema antigo mostrava em "PDV:"). */
    public function tipo(): string
    {
        return implode(' e ', array_keys(array_filter([
            Contingencia::SACTUS => $this->totalDo(Contingencia::SACTUS) > 0,
            Contingencia::VERSATUS => $this->totalDo(Contingencia::VERSATUS) > 0,
        ])));
    }
}
