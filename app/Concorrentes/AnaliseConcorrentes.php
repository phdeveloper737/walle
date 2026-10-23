<?php

namespace App\Concorrentes;

use Illuminate\Support\Carbon;

/**
 * Análise competitiva da JM Sistemas. Quem gera é o squad "concorrentes-analise-competitiva" do ExpxAgents,
 * que grava o analise-concorrentes.json desta pasta; a tela de Concorrentes só lê e mostra.
 */
class AnaliseConcorrentes
{
    /** Situação de cada célula da matriz comparativa. "nd" = não divulgado nas fontes públicas (não quer dizer que não tem). */
    public const SITUACOES = [
        'sim' => 'Tem',
        'parcial' => 'Parcial',
        'nd' => 'Não divulgado',
    ];

    public function __construct(private ?string $arquivo = null) {}

    /**
     * Lê a análise; null quando o arquivo não existe. Cada célula da matriz vem como "situação|nota"
     * ("parcial|generalista") e sai como ['situacao' => 'parcial', 'nota' => 'generalista'].
     *
     * @return array<string, mixed>|null
     */
    public function carregar(): ?array
    {
        $arquivo = $this->arquivo ?? __DIR__.'/analise-concorrentes.json';

        if (! is_file($arquivo)) {
            return null;
        }

        /** @var array<string, mixed> $analise */
        $analise = json_decode((string) file_get_contents($arquivo), true, flags: JSON_THROW_ON_ERROR);

        $analise['atualizada_em'] = Carbon::parse($analise['atualizada_em']);
        $analise['matriz']['linhas'] = array_map(fn (array $linha): array => [
            ...$linha,
            'valores' => array_map($this->celula(...), $linha['valores']),
        ], $analise['matriz']['linhas']);

        return $analise;
    }

    /** @return array{situacao: string, nota: string|null} */
    private function celula(string $valor): array
    {
        [$situacao, $nota] = array_pad(explode('|', $valor, 2), 2, null);

        return ['situacao' => $situacao, 'nota' => $nota];
    }
}
