<?php

namespace App\Contingencias;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Um cliente da lista lateral de Contingências (dados do Help Desk, 192.168.10.254).
 *
 * Só leva o que a tela mostra: IP, PIN, senhas e banco do cliente NÃO vão para o navegador
 * (no sistema antigo iam escondidos em atributos do HTML).
 */
final readonly class ClienteDaLista
{
    public function __construct(
        public int $id,
        public string $razaoSocial,
        public string $cnpj,
        public bool $consultoria,
        public string $loja,
        public string $tipoPdv,
        public bool $servidorLocal,
        public int $totalContingencias,
        public ?string $ultimaAtualizacao,
    ) {}

    /** @param array<string, mixed> $linha Linha da consulta em ListaDeClientes */
    public static function deLinha(array $linha): self
    {
        return new self(
            id: (int) $linha['id'],
            razaoSocial: self::texto($linha['razao_social']),
            cnpj: self::texto($linha['cnpj']),
            consultoria: self::texto($linha['consultoria']) === 'S',
            loja: self::texto($linha['loja']),
            // Mesma regra do sistema antigo: só V e S têm nome; N, A e vazio ficam "NÃO DEFINIDO"
            tipoPdv: match (self::texto($linha['servidor_multiempresa'])) {
                'V' => 'VERSATUS',
                'S' => 'SACTUS',
                default => 'NÃO DEFINIDO',
            },
            // "SRV LOCAL": servidor na empresa do cliente, pela VPN (10.8.0.x), fora do Docker
            servidorLocal: self::texto($linha['local_servidor']) === 'EMPRESA'
                && str_starts_with(self::texto($linha['ip_externo_servidor']), '10.8.0.')
                && self::texto($linha['pasta_multiempresa']) === 'N',
            totalContingencias: (int) $linha['total_linux'] + (int) $linha['total_versatus'],
            ultimaAtualizacao: $linha['ultima_atualizacao']
                ? Carbon::parse((string) $linha['ultima_atualizacao'])->format('d/m/Y H:i')
                : null,
        );
    }

    /** Busca como no sistema antigo: qualquer parte do card (id, razão social, CNPJ, tipo), sem diferenciar acento e maiúscula. */
    public function corresponde(string $termo): bool
    {
        $termo = self::normalizar($termo);

        if ($termo === '') {
            return true;
        }

        $digitos = preg_replace('/\D/', '', $termo);
        $cnpjDigitos = preg_replace('/\D/', '', $this->cnpj);

        return str_contains(self::normalizar("#{$this->id} {$this->razaoSocial} {$this->cnpj} {$this->tipoPdv}"), $termo)
            || ($digitos !== '' && strlen($digitos) >= 3 && str_contains($cnpjDigitos, $digitos));
    }

    private static function texto(mixed $valor): string
    {
        // Alguns nomes no Help Desk vêm com TAB ou espaço sobrando no fim
        return trim((string) $valor);
    }

    private static function normalizar(string $texto): string
    {
        return Str::lower(Str::ascii(trim($texto)));
    }
}
