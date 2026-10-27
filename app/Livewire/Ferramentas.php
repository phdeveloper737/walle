<?php

namespace App\Livewire;

use App\Livewire\Concerns\ConfirmaSenha;
use App\Models\Auditoria;
use App\Models\TaskSchedulerQuery;
use App\Models\TaskSchedulerQueryLog;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Ferramentas: substitui o tools do 220 (programacao/tools). Admin cadastra as queries do Task Scheduler
 * (aba Queries) e a equipe vê as últimas execuções (aba Relatório). A tela não executa SQL nem conecta em
 * cliente: quem roda é o RoboTarefas. A query fica em base64 em query_execute, como no 220 e como o robô lê.
 *
 * @property-read Collection<int, TaskSchedulerQuery> $queries
 * @property-read Collection<int, TaskSchedulerQueryLog> $execucoes
 * @property-read array{nomes: array<int, string>, erro: bool} $clientes
 */
#[Title('Ferramentas')]
class Ferramentas extends Component
{
    use ConfirmaSenha;

    public const ABAS = [
        'querys' => ['rotulo' => 'Queries', 'icone' => 'terminal'],
        'relatorio' => ['rotulo' => 'Relatório', 'icone' => 'summarize'],
    ];

    public const ACOES_COM_SENHA = ['editar', 'salvar', 'alternarAtivo'];

    /** Tamanho da coluna query_execute (base64). */
    public const LIMITE_CODIFICADO = 3737;

    /** Bytes de SQL puro que cabem na coluna depois do base64: 4 * ceil(2802 / 3) = 3736. */
    public const LIMITE_SQL = 2802;

    public const LIMITE_EXECUCOES = 200;

    #[Url(except: 'querys')]
    public string $aba = 'querys';

    public string $descricao = '';

    public string $sql = '';

    #[Locked]
    public ?int $editandoId = null;

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

    public static function codificarSql(string $sql): string
    {
        return base64_encode(trim($sql));
    }

    public static function decodificarSql(?string $codificado): string
    {
        $sql = base64_decode(trim((string) $codificado), true);

        return $sql === false ? '' : $sql;
    }

    /** Resultado do robô: texto FALHA fica como está; o resto é base64, e valor que não for base64 válido também. */
    public static function decodificarLog(?string $log): string
    {
        $log = trim((string) $log);

        if ($log === '' || str_starts_with($log, 'FALHA')) {
            return $log;
        }

        $texto = base64_decode($log, true);

        return $texto === false || ! mb_check_encoding($texto, 'UTF-8') ? $log : $texto;
    }

    // Sem injeção nos parâmetros: depois da senha o trait chama o método direto, só com os argumentos guardados

    public function editar(int $id): void
    {
        if (! $this->adminConfirmado('editar', [$id])) {
            return;
        }

        $query = TaskSchedulerQuery::query()->where('deletado', 'N')->findOrFail($id);
        $this->editandoId = $query->id;
        $this->descricao = $query->descricao;
        $this->sql = self::decodificarSql($query->query_execute);
        $this->aviso = null;
        Auditoria::registrar('ferramentas.editar_query', null, ['id' => $query->id]);
    }

    public function salvar(): void
    {
        abort_unless(Gate::allows('admin'), 403, 'Só os admins do walle podem fazer isso.');

        $this->validate([
            'descricao' => ['required', 'string', 'max:373'],
            'sql' => ['required', 'string', function (string $atributo, mixed $valor, \Closure $falhar): void {
                if (strlen(self::codificarSql((string) $valor)) > self::LIMITE_CODIFICADO) {
                    $falhar('A query é grande demais: o limite é de '.self::LIMITE_SQL.' bytes (conta bytes, não letras; acentos valem mais de um).');
                }
            }],
        ], [], ['descricao' => 'descrição', 'sql' => 'query']);

        if (! $this->adminConfirmado('salvar')) {
            return;
        }

        $codificada = self::codificarSql($this->sql);

        if ($this->editandoId !== null) {
            $query = TaskSchedulerQuery::query()->where('deletado', 'N')->findOrFail($this->editandoId);
            $query->update(['descricao' => trim($this->descricao), 'query_execute' => $codificada]);
            $acao = 'ferramentas.editar_query';
        } else {
            $query = TaskSchedulerQuery::query()->create([
                'fk_a_user' => Auth::id(), 'descricao' => trim($this->descricao), 'query_execute' => $codificada,
                'data_registro' => now(), 'finalizado' => 'N', 'ativo' => 'N', 'deletado' => 'N',
            ]);
            $acao = 'ferramentas.salvar_query';
        }

        Auditoria::registrar($acao, null, ['id' => $query->id, 'ativo' => $query->ativo === 'S']);
        $this->aviso = $acao === 'ferramentas.salvar_query'
            ? 'Query salva, desativada. Ative quando quiser que o robô a rode.'
            : 'Query atualizada.';
        $this->cancelarEdicao();
    }

    public function alternarAtivo(int $id): void
    {
        if (! $this->adminConfirmado('alternarAtivo', [$id])) {
            return;
        }

        $query = TaskSchedulerQuery::query()->where('deletado', 'N')->findOrFail($id);
        $query->update(['ativo' => $query->ativo === 'S' ? 'N' : 'S']);

        Auditoria::registrar('ferramentas.alternar_query', null, ['id' => $query->id, 'ativo' => $query->ativo === 'S']);
        $this->aviso = $query->ativo === 'S' ? 'Query ativada.' : 'Query desativada.';
    }

    public function cancelarEdicao(): void
    {
        $this->reset('editandoId', 'descricao', 'sql');
        $this->resetErrorBag();
    }

    /** @return Collection<int, TaskSchedulerQuery> */
    #[Computed]
    public function queries(): Collection
    {
        return TaskSchedulerQuery::query()->where('deletado', 'N')->orderByDesc('id')
            ->select(['id', 'descricao', 'data_registro', 'ativo'])->get();
    }

    /** @return Collection<int, TaskSchedulerQueryLog> */
    #[Computed]
    public function execucoes(): Collection
    {
        return TaskSchedulerQueryLog::query()->with('taskSchedulerQuery:id,descricao')
            ->orderByDesc('data_time')->limit(self::LIMITE_EXECUCOES)->get();
    }

    /**
     * Nome dos clientes das execuções, pelo Help Desk (só leitura). Sem execuções não consulta.
     *
     * @return array{nomes: array<int, string>, erro: bool}
     */
    #[Computed]
    public function clientes(): array
    {
        $ids = $this->execucoes->pluck('fk_c_cliente')->filter()->unique()->values()->all();

        if ($ids === []) {
            return ['nomes' => [], 'erro' => false];
        }

        try {
            /** @var array<int, string> $nomes */
            $nomes = DB::connection('help')->table('c_cliente')->whereIn('id', $ids)->pluck('razao_social', 'id')->all();

            return ['nomes' => $nomes, 'erro' => false];
        } catch (QueryException $e) {
            report($e);

            return ['nomes' => [], 'erro' => true];
        }
    }

    public function render(): View
    {
        return view('livewire.ferramentas', [
            'admin' => Gate::allows('admin'),
            'ligado' => (bool) config('walle.tarefas.ativo'),
        ]);
    }
}
