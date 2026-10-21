<?php

namespace App\Sefaz;

/**
 * Chave de acesso de NF-e / NFC-e (44 dígitos):
 * cUF(2) AAMM(4) CNPJ(14) modelo(2) série(3) número(9) tpEmis(1) código(8) DV(1).
 */
final readonly class ChaveDeAcesso
{
    public function __construct(public string $chave) {}

    /** Só os dígitos (aceita a chave com espaços, pontos ou o prefixo "NFe"). */
    public static function de(mixed $valor): self
    {
        return new self(preg_replace('/\D/', '', (string) $valor) ?? '');
    }

    /**
     * Mesmas validações do consulta.nfce.php do sistema antigo.
     *
     * @return string|null null = válida para consultar na Sefaz de $uf
     */
    public function problema(string $uf = '52'): ?string
    {
        if (strlen($this->chave) !== 44) {
            return 'Chave deve ter 44 dígitos';
        }

        if ($this->uf() !== $uf) {
            return "Chave não pertence à UF {$uf} (cUF {$this->uf()})";
        }

        if (! in_array($this->modelo(), ['55', '65'], true)) {
            return "Modelo inválido ({$this->modelo()})";
        }

        if (! $this->digitoValido()) {
            return 'Dígito verificador inválido';
        }

        return null;
    }

    /** DV: módulo 11, pesos 2..9 da direita para a esquerda. */
    public function digitoValido(): bool
    {
        if (strlen($this->chave) !== 44) {
            return false;
        }

        $soma = 0;
        $peso = 2;

        for ($i = 42; $i >= 0; $i--) {
            $soma += (int) $this->chave[$i] * $peso;
            $peso = $peso === 9 ? 2 : $peso + 1;
        }

        $resto = $soma % 11;
        $dv = $resto < 2 ? 0 : 11 - $resto;

        return $dv === (int) $this->chave[43];
    }

    public function uf(): string
    {
        return substr($this->chave, 0, 2);
    }

    /** Primeiro dia do mês de emissão (AAMM da chave), ou null se a chave não tiver um mês válido. */
    public function referencia(): ?string
    {
        $ano = substr($this->chave, 2, 2);
        $mes = substr($this->chave, 4, 2);

        if (! ctype_digit($ano) || ! ctype_digit($mes) || (int) $mes < 1 || (int) $mes > 12) {
            return null;
        }

        return "20{$ano}-{$mes}-01";
    }

    public function cnpj(): string
    {
        return substr($this->chave, 6, 14);
    }

    public function cnpjFormatado(): string
    {
        $c = $this->cnpj();

        return strlen($c) === 14
            ? substr($c, 0, 2).'.'.substr($c, 2, 3).'.'.substr($c, 5, 3).'/'.substr($c, 8, 4).'-'.substr($c, 12, 2)
            : $c;
    }

    public function modelo(): string
    {
        return substr($this->chave, 20, 2);
    }

    public function serie(): string
    {
        return substr($this->chave, 22, 3);
    }

    public function numero(): string
    {
        return substr($this->chave, 25, 9);
    }

    /** Tipo de emissão: 1 normal, 9 contingência off-line da NFC-e etc. */
    public function tipoEmissao(): string
    {
        return substr($this->chave, 34, 1);
    }

    /** Sigla da UF pelo código do IBGE (52 → GO). */
    public static function siglaUf(string $cUF): string
    {
        return [
            '11' => 'RO', '12' => 'AC', '13' => 'AM', '14' => 'RR', '15' => 'PA', '16' => 'AP', '17' => 'TO',
            '21' => 'MA', '22' => 'PI', '23' => 'CE', '24' => 'RN', '25' => 'PB', '26' => 'PE', '27' => 'AL',
            '28' => 'SE', '29' => 'BA', '31' => 'MG', '32' => 'ES', '33' => 'RJ', '35' => 'SP', '41' => 'PR',
            '42' => 'SC', '43' => 'RS', '50' => 'MS', '51' => 'MT', '52' => 'GO', '53' => 'DF',
        ][$cUF] ?? "cUF {$cUF}";
    }

    public static function rotuloModelo(string $modelo): string
    {
        return match ($modelo) {
            '55' => 'NF-e (55)',
            '65' => 'NFC-e (65)',
            default => "Modelo {$modelo}",
        };
    }

    public static function rotuloTipoEmissao(string $tipo): string
    {
        return match ($tipo) {
            '1' => 'Normal',
            '2' => 'FS-IA',
            '3' => 'SCAN',
            '4' => 'EPEC',
            '5' => 'FS-DA',
            '6' => 'SVC-AN',
            '7' => 'SVC-RS',
            '9' => 'Off-line NFC-e',
            default => "tpEmis {$tipo}",
        };
    }
}
