<?php

namespace App\Livewire;

use App\Livewire\Concerns\ConfirmaSenha;
use App\Models\Auditoria;
use App\Models\TaskSchedulerQuery;
use App\Models\TaskSchedulerQueryLog;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Ferramentas: queries agendadas do Task Scheduler (criar, editar, ativar e desativar, só admin)
 * e o relatório das execuções. Substitui o programacao/tools e o programacao/tools/reports do sistema antigo.
 * A tela só cadastra e lê: quem executa a query no cliente é o robô das tarefas.
 *
 * @property-read Collection<int, TaskSchedulerQuery> $queries
 * @property-read Collection<int, TaskSchedulerQueryLog> $logs
 * @property-read array<int, string>|null $nomes
 */
#[Title('Ferramentas')]
class Ferramentas extends Component
{
    use ConfirmaSenha;

    public const ABAS = [
        'querys' => ['rotulo' => 'Queries agendadas', 'icone' => 'schedule'],
        'relatorio' => ['rotulo' => 'Relatório', 'icone' => 'summarize'],
    ];

    public const ACOES_COM_SENHA = ['editar', 'salvar', 'alternarAtivo'];

    public const LIMITE_LOGS = 200;

    #[Url(except: 'querys')]
    public string $aba = 'querys';

    #[Locked]
    public ?int $editandoId = null;

    public string $descricao = '';

    public string $queryExecute = '';

    public ?string $aviso = null;

    public function mount(): void
    {
        $this->updatedAba();
    }

    public function updatedAba(): void
    {
        if (! array_key_exists($this->aba, self::ABAS)) {
            $this->aba = 'querys';
        }
    }

    /** Carrega a query no formulário. O SQL só chega à tela de um admin com a senha confirmada. */
    public function editar(int $id): void
    {
        if (! $this->adminConfirmado('editar', [$id])) {
            return;
        }

        $query = TaskSchedulerQuery::query()->where('deletado', 'N')->find($id);

        if ($query === null) {
            $this->aviso = 'Query não encontrada. Atualize a página e tente de novo.';

            return;
        }

        $this->editandoId = $query->id;
        $this->descricao = $query->descricao;
        $this->queryExecute = (string) $query->query_execute;
        $this->aviso = null;
        $this->resetErrorBag();
    }

    public function cancelarEdicao(): void
    {
        $this->reset('editandoId', 'descricao', 'queryExecute');
        $this->resetErrorBag();
    }

    public function salvar(): void
    {
        if (! $this->adminConfirmado('salvar')) {
            return;
        }

        $dados = $this->validate([
            'descricao' => ['required', 'string', 'max:373'],
            'queryExecute' => ['required', 'string', 'max:3737'],
        ], [
            'descricao.required' => 'Informe a descrição da query.',
            'descricao.max' => 'A descrição pode ter até 373 caracteres.',
            'queryExecute.required' => 'Informe a query a executar.',
            'queryExecute.max' => 'A query pode ter até 3737 caracteres.',
        ]);

        if ($this->editandoId !== null) {
            $query = TaskSchedulerQuery::query()->where('deletado', 'N')->find($this->editandoId);

            if ($query === null) {
                $this->aviso = 'Query não encontrada. Atualize a página e tente de novo.';

                return;
            }

            $query->update(['descricao' => $dados['descricao'], 'query_execute' => $dados['queryExecute']]);
            $acao = 'ferramentas.editar_query';
            $this->aviso = 'Query atualizada.';
        } else {
            // Nasce desativada: o admin liga quando conferir o texto
            $query = TaskSchedulerQuery::create([
                'fk_a_user' => auth()->id(),
                'descricao' => $dados['descricao'],
                'query_execute' => $dados['queryExecute'],
                'data_registro' => now(),
                'finalizado' => 'N',
                'ativo' => 'N',
                'deletado' => 'N',
            ]);
            $acao = 'ferramentas.salvar_query';
            $this->aviso = 'Query salva. Ela começa desativada: ative quando quiser que o robô rode.';
        }

        // O texto da query não vai para a auditoria: pode ter valor sensível
        Auditoria::registrar($acao, null, ['query' => $query->id]);
        $this->cancelarEdicao();
        unset($this->queries);
    }

    public function alternarAtivo(int $id): void
    {
        if (! $this->adminConfirmado('alternarAtivo', [$id])) {
            return;
        }

        $query = TaskSchedulerQuery::query()->where('deletado', 'N')->find($id);

        if ($query === null) {
            $this->aviso = 'Query não encontrada. Atualize a página e tente de novo.';

            return;
        }

        $ativar = $query->ativo !== 'S';
        $query->update(['ativo' => $ativar ? 'S' : 'N']);
        Auditoria::registrar('ferramentas.alternar_query', null, ['query' => $query->id, 'ativo' => $ativar]);
        $this->aviso = $ativar ? 'Query ativada.' : 'Query desativada.';
        unset($this->queries);
    }

    /** @return Collection<int, TaskSchedulerQuery> */
    #[Computed]
    public function queries(): Collection
    {
        return TaskSchedulerQuery::query()->where('deletado', 'N')->latest('id')->get();
    }

    /** @return Collection<int, TaskSchedulerQueryLog> */
    #[Computed]
    public function logs(): Collection
    {
        return TaskSchedulerQueryLog::query()->with('taskSchedulerQuery:id,descricao')
            ->orderByDesc('data_time')->limit(self::LIMITE_LOGS)->get();
    }

    /** @return array<int, string>|null null quando o Help Desk não responde */
    #[Computed]
    public function nomes(): ?array
    {
        try {
            return DB::connection('help')->table('c_cliente')
                ->whereIn('id', $this->logs->pluck('fk_c_cliente')->filter()->unique()->all())
                ->pluck('razao_social', 'id')->map(fn ($nome): string => trim((string) $nome))->all();
        } catch (QueryException $e) {
            report($e);

            return null;
        }
    }

    public function render(): View
    {
        return view('livewire.ferramentas');
    }
}
