<?php

namespace Tests\Support;

use App\Sactus\Prechecagem;

/** Pré-checagem sem rede: responde "no ar" para todos, menos os 'host|porta' marcados como fora. */
class PrechecagemFalsa extends Prechecagem
{
    /** @param list<string> $foraDoAr */
    public function __construct(private readonly array $foraDoAr = []) {}

    public function responde(array $pares): array
    {
        $resultado = [];

        foreach (array_keys($pares) as $chave) {
            $resultado[$chave] = ! in_array($chave, $this->foraDoAr, true);
        }

        return $resultado;
    }
}
