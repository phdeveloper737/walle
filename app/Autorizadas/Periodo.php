<?php

namespace App\Autorizadas;

/** Período da análise, sobre a data de referência das chaves (intervalo fechado). */
final readonly class Periodo
{
    /** Atalhos da tela, na ordem em que aparecem. */
    public const ATALHOS = [
        'hoje' => 'Hoje',
        'ontem' => 'Ontem',
        '7d' => '7 dias',
        '30d' => '30 dias',
        'mes' => 'Este mês',
        'mes_anterior' => 'Mês anterior',
        'tudo' => 'Toda a base',
    ];

    public function __construct(
        public string $de,
        public string $ate,
        public string $rotulo,
    ) {}

    public static function doAtalho(string $atalho, string $corteRetencao): self
    {
        $hoje = now()->toDateString();

        return match ($atalho) {
            'hoje' => new self($hoje, $hoje, 'Hoje'),
            'ontem' => new self(now()->subDay()->toDateString(), now()->subDay()->toDateString(), 'Ontem'),
            '7d' => new self(now()->subDays(6)->toDateString(), $hoje, 'Últimos 7 dias'),
            '30d' => new self(now()->subDays(29)->toDateString(), $hoje, 'Últimos 30 dias'),
            'mes_anterior' => new self(
                now()->subMonthNoOverflow()->startOfMonth()->toDateString(),
                now()->subMonthNoOverflow()->endOfMonth()->toDateString(),
                'Mês anterior',
            ),
            'tudo' => new self($corteRetencao, $hoje, 'Toda a base'),
            default => new self(now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString(), 'Este mês'),
        };
    }
}
