<?php

namespace App\Sefaz;

/** Uma sessão aberta com o web service da Sefaz (manda envelopes SOAP e devolve a resposta crua). */
interface SessaoSefaz
{
    /** @return array{http: int, corpo: string|null, erro: string|null} */
    public function enviar(string $envelope): array;

    public function fechar(): void;
}
