<?php

namespace App\Livewire\Sactus;

use App\Models\PainelSactusCfg;
use App\Sactus\ArquivoCfg;
use App\Sactus\PainelSactus;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Verificador: uma flag (e, se quiser, o valor esperado) conferida em todos os clientes de uma vez.
 * Mesma comparação do painel antigo (sem espaços nas pontas e sem diferença de maiúsculas).
 * Flag sensível: a comparação é feita aqui no servidor e o valor não vai para a tela.
 *
 * @property-read list<array{id: int, nome: string, situacao: string, valor: string|null}> $resultado
 * @property-read list<string> $nomesDeFlags
 */
class Verificador extends Component
{
    public const SITUACOES = [
        'confere' => 'Confere',
        'diferente' => 'Diferente',
        'ausente' => 'Sem a flag',
        'sem_coleta' => 'Sem coleta',
    ];

    #[Url(except: '')]
    public string $flag = '';

    #[Url(except: '')]
    public string $valor = '';

    #[Url(as: 'so', except: '')]
    public string $situacao = '';

    /** @return list<array{id: int, nome: string, situacao: string, valor: string|null}> */
    #[Computed]
    public function resultado(): array
    {
        $flag = trim($this->flag);

        if ($flag === '') {
            return [];
        }

        $nomes = array_column(app(PainelSactus::class)->linhas(), 'nome', 'id');
        $esperado = mb_strtolower(trim($this->valor));
        $sensivel = ArquivoCfg::sensivel($flag);
        $resultado = [];

        foreach (PainelSactusCfg::query()->get(['fk_c_cliente', 'status', 'json']) as $p) {
            $flags = $p->flags();
            $tem = array_key_exists($flag, $flags);

            $situacao = match (true) {
                ! $p->coletado() => 'sem_coleta',
                ! $tem => 'ausente',
                // Sem valor esperado, só a presença da flag conta
                trim($this->valor) === '' || mb_strtolower(trim($flags[$flag])) === $esperado => 'confere',
                default => 'diferente',
            };

            $resultado[] = [
                'id' => $p->fk_c_cliente,
                'nome' => $nomes[$p->fk_c_cliente] ?? 'Cliente #'.$p->fk_c_cliente,
                'situacao' => $situacao,
                'valor' => $tem ? ($sensivel ? '••••••' : $flags[$flag]) : null,
            ];
        }

        // Os que pedem atenção primeiro
        $ordem = array_flip(['diferente', 'ausente', 'sem_coleta', 'confere']);
        usort($resultado, fn (array $a, array $b): int => [$ordem[$a['situacao']], $a['nome']] <=> [$ordem[$b['situacao']], $b['nome']]);

        return $resultado;
    }

    /** @return list<string> */
    #[Computed]
    public function nomesDeFlags(): array
    {
        return PainelSactus::nomesDeFlags(app(PainelSactus::class)->linhas());
    }

    public function render(): View
    {
        $contagem = array_count_values(array_column($this->resultado, 'situacao'));

        return view('livewire.sactus.verificador', [
            'contagem' => $contagem,
            'visiveis' => array_values(array_filter($this->resultado, fn (array $r): bool => $this->situacao === '' || $r['situacao'] === $this->situacao)),
        ]);
    }
}
