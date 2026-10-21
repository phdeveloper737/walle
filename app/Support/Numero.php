<?php

namespace App\Support;

/** Números no jeito brasileiro (1.284 · 12,9 mil · 87,5%) e a escala dos gráficos. */
final class Numero
{
    public static function inteiro(int|float $n): string
    {
        return number_format((float) $n, 0, ',', '.');
    }

    /** Para eixos e espaços pequenos: 950 · 1,2 mil · 3,4 mi. */
    public static function compacto(int|float $n): string
    {
        $abs = abs($n);

        return match (true) {
            $abs >= 1_000_000 => self::curto($n / 1_000_000).' mi',
            $abs >= 1_000 => self::curto($n / 1_000).' mil',
            default => self::inteiro($n),
        };
    }

    public static function decimal(float $n, int $casas = 1): string
    {
        return number_format($n, $casas, ',', '.');
    }

    public static function percentual(float $n, int $casas = 1): string
    {
        return self::decimal($n, $casas).'%';
    }

    /**
     * Eixo com números redondos (passo 1, 2 ou 5 × 10^n) que cobre o maior valor.
     *
     * @return array{topo: float, marcas: list<float>}
     */
    public static function escala(float $maior, int $divisoes = 4): array
    {
        if ($maior <= 0) {
            return ['topo' => 1.0, 'marcas' => [0.0, 1.0]];
        }

        $bruto = $maior / $divisoes;
        $potencia = 10 ** floor(log10($bruto));
        $passo = $potencia * match (true) {
            $bruto / $potencia <= 1 => 1,
            $bruto / $potencia <= 2 => 2,
            $bruto / $potencia <= 5 => 5,
            default => 10,
        };
        $passo = max($passo, 1.0);
        $topo = ceil($maior / $passo) * $passo;

        return ['topo' => $topo, 'marcas' => range(0.0, $topo, $passo)];
    }

    /** Uma casa abaixo de 10 (1,2 · 9,5), sem o ",0" (2 e não 2,0). */
    private static function curto(float $n): string
    {
        $texto = number_format($n, abs($n) < 10 ? 1 : 0, ',', '.');

        return str_ends_with($texto, ',0') ? substr($texto, 0, -2) : $texto;
    }
}
