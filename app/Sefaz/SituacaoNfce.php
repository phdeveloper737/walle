<?php

namespace App\Sefaz;

/** Situação de uma NF-e/NFC-e na Sefaz (mesma tabela de cStat do consulta.nfce.php antigo). */
enum SituacaoNfce: string
{
    case Autorizada = 'AUTORIZADA';
    case Cancelada = 'CANCELADA';
    case Denegada = 'DENEGADA';
    case Inexistente = 'INEXISTENTE';
    case UfDivergente = 'UF_DIVERGENTE';
    case ConsumoIndevido = 'CONSUMO_INDEVIDO';
    case Indefinida = 'INDEFINIDO';
    case Erro = 'ERRO';

    public static function doCStat(string $cStat, bool $cancelada = false): self
    {
        if ($cancelada) {
            return self::Cancelada;
        }

        return match ($cStat) {
            '100' => self::Autorizada,
            '101', '135', '151', '155' => self::Cancelada,
            '110', '301', '302', '303' => self::Denegada,
            '217' => self::Inexistente,
            '226' => self::UfDivergente,
            '656' => self::ConsumoIndevido,
            default => self::Indefinida,
        };
    }

    public function rotulo(): string
    {
        return match ($this) {
            self::Autorizada => 'Autorizada',
            self::Cancelada => 'Cancelada',
            self::Denegada => 'Denegada',
            self::Inexistente => 'Não existe na Sefaz',
            self::UfDivergente => 'UF divergente',
            self::ConsumoIndevido => 'Consumo indevido (bloqueio da Sefaz)',
            self::Indefinida => 'Indefinida',
            self::Erro => 'Erro na consulta',
        };
    }
}
