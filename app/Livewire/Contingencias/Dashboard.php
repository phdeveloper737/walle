<?php

namespace App\Livewire\Contingencias;

use App\Models\PainelContingencyCrawler;
use App\Support\ConfiguracaoRobo;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Painel do Contingência BOT em cards, um por cliente (substitui o contingency.dash.php).
 * Os números vêm do painel_contingency_crawler; nome, CNPJ e tipo ao vivo do Help Desk (192.168.10.254, só leitura).
 *
 * @property-read Collection<int, PainelContingencyCrawler> $painel
 * @property-read array{ok: bool, clientes: array<int, array{nome: string, cnpj: ?string, emissao: ?string}>} $dadosDoHelp
 * @property-read list<array{id: int, nome: string, cnpj: ?string, emissao: ?string, totalCliente: int, totalBot: int, totalSactus: int, totalVersatus: int, recente: bool, data: Carbon}> $clientes
 * @property-read list<array{id: int, nome: string, cnpj: ?string, emissao: ?string, totalCliente: int, totalBot: int, totalSactus: int, totalVersatus: int, recente: bool, data: Carbon}> $visiveis
 * @property-read array{ok: int, contingencia: int, total: int} $resumo
 */
class Dashboard extends Component
{
    /** Minutos na mesma situação do relógio do card: até 5 a coleta está atual. */
    public const MINUTOS_ATUAL = 5;

    /** Segundos que os dados do Help Desk ficam guardados para o poll de 10 s não consultá-lo a cada atualização. */
    public const SEGUNDOS_DO_HELP = 300;

    /** O card filtrado mostra só os OK. */
    public const SITUACAO_OK = 'ok';

    /** O card filtrado mostra só os com contingência. */
    public const SITUACAO_CONTINGENCIA = 'contingencia';

    /** S = kits automáticos sim; N = não; '' = todos. Mesmos rótulos dos Relatórios. */
    public const TIPOS = ['S', 'N', ''];

    #[Url(except: '')]
    public string $situacao = '';

    #[Url(except: '')]
    public string $tipo = '';

    #[Url(except: '')]
    public string $busca = '';

    public bool $pausado = false;

    /** Hora da última atualização da tela (abertura ou poll). */
    public ?string $atualizadoEm = null;

    public function mount(): void
    {
        $this->validar();
        $this->atualizar();
    }

    public function updatedSituacao(): void
    {
        $this->validar();
    }

    public function updatedTipo(): void
    {
        $this->validar();
    }

    private function validar(): void
    {
        if (! in_array($this->situacao, [self::SITUACAO_OK, self::SITUACAO_CONTINGENCIA, ''], true)) {
            $this->situacao = '';
        }

        if (! in_array($this->tipo, self::TIPOS, true)) {
            $this->tipo = '';
        }
    }

    /** Chamado pelo `wire:poll.10s` da view e na abertura: marca a hora da última atualização. */
    public function atualizar(): void
    {
        $this->atualizadoEm = now()->format('H:i:s');
    }

    public function alternarPoll(): void
    {
        $this->pausado = ! $this->pausado;
    }

    /** Clicar no contador liga o filtro; clicar de novo limpa. */
    public function alternarSituacao(string $situacao): void
    {
        $this->situacao = $this->situacao === $situacao ? '' : $situacao;
    }

    public function limparFiltros(): void
    {
        $this->situacao = '';
        $this->tipo = '';
        $this->busca = '';
    }

    /** @return Collection<int, PainelContingencyCrawler> */
    #[Computed]
    public function painel(): Collection
    {
        return PainelContingencyCrawler::query()
            ->whereNotNull('fk_c_cliente')
            ->orderByDesc('total_cliente')
            ->orderBy('fk_c_cliente')
            ->get();
    }

    /**
     * Nome, CNPJ e tipo dos clientes do painel, ao vivo do Help Desk. Guardados em cache por
     * SEGUNDOS_DO_HELP: com o poll de 10 s, a consulta ao Help Desk não acontece a cada atualização.
     * Só o resultado bem-sucedido entra no cache (uma pane curta não fica congelada por 5 minutos).
     *
     * @return array{ok: bool, clientes: array<int, array{nome: string, cnpj: ?string, emissao: ?string}>}
     *                                                                                                     ok = false quando o Help Desk não respondeu (os cards seguem com os números do painel)
     */
    #[Computed]
    public function dadosDoHelp(): array
    {
        $ids = $this->painel->pluck('fk_c_cliente')->map(fn ($id): int => (int) $id)->all();

        if ($ids === []) {
            return ['ok' => true, 'clientes' => []];
        }

        $chave = 'contingencias.dashboard-help-'.md5(implode(',', $ids));
        $emCache = Cache::get($chave);

        if (is_array($emCache)) {
            return $emCache;
        }

        try {
            $linhas = DB::connection('help')->table('c_cliente')->whereIn('id', $ids)
                ->get(['id', 'razao_social', 'nome_fantasia', 'cnpj']);
            $emissoes = DB::connection('help')->table('informacao_tecnica_cleantaxbot')
                ->whereIn('fk_c_cliente', $ids)->pluck('emissao_cupons', 'fk_c_cliente');
        } catch (QueryException $e) {
            report($e);

            return ['ok' => false, 'clientes' => []];
        }

        /** @var array<int, array{nome: string, cnpj: ?string, emissao: ?string}> $clientes */
        $clientes = [];

        foreach ($linhas as $linha) {
            $id = (int) $linha->id;
            $razao = trim((string) $linha->razao_social);
            $fantasia = trim((string) $linha->nome_fantasia);
            $clientes[$id] = [
                'nome' => $razao !== '' ? $razao : ($fantasia !== '' ? $fantasia : 'Cliente #'.$id),
                'cnpj' => self::formatarCnpj((string) $linha->cnpj),
                'emissao' => $emissoes->get($id),
            ];
        }

        $dados = ['ok' => true, 'clientes' => $clientes];
        Cache::put($chave, $dados, self::SEGUNDOS_DO_HELP);

        return $dados;
    }

    /**
     * Um card por linha do painel, do maior total para o menor, com empatia pelo id do cliente.
     *
     * @return list<array{id: int, nome: string, cnpj: ?string, emissao: ?string, totalCliente: int, totalBot: int, totalSactus: int, totalVersatus: int, recente: bool, data: Carbon}>
     */
    #[Computed]
    public function clientes(): array
    {
        $corte = now()->subMinutes(self::MINUTOS_ATUAL);
        $help = $this->dadosDoHelp()['clientes'];

        return $this->painel
            ->map(fn (PainelContingencyCrawler $linha): array => [
                'id' => (int) $linha->fk_c_cliente,
                'nome' => $help[(int) $linha->fk_c_cliente]['nome'] ?? 'Cliente #'.$linha->fk_c_cliente,
                'cnpj' => $help[(int) $linha->fk_c_cliente]['cnpj'] ?? null,
                'emissao' => $help[(int) $linha->fk_c_cliente]['emissao'] ?? null,
                'totalCliente' => (int) ($linha->total_cliente ?? 0),
                'totalBot' => (int) ($linha->total_bot ?? 0),
                'totalSactus' => (int) ($linha->total_sactus ?? 0),
                'totalVersatus' => (int) ($linha->total_versatus ?? 0),
                'recente' => $linha->data->greaterThanOrEqualTo($corte),
                'data' => $linha->data,
            ])
            ->all();
    }

    /**
     * Cards que passam pelos filtros de tipo (Help Desk de pé), busca e situação.
     *
     * @return list<array{id: int, nome: string, cnpj: ?string, emissao: ?string, totalCliente: int, totalBot: int, totalSactus: int, totalVersatus: int, recente: bool, data: Carbon}>
     */
    #[Computed]
    public function visiveis(): array
    {
        return collect($this->clientes)
            ->filter(fn (array $cliente): bool => $this->passaTipoEBusca($cliente)
                && match ($this->situacao) {
                    self::SITUACAO_OK => $cliente['totalCliente'] === 0,
                    self::SITUACAO_CONTINGENCIA => $cliente['totalCliente'] > 0,
                    default => true,
                })
            ->values()
            ->all();
    }

    /**
     * Contadores dos cards que passariam por tipo e busca (sem o filtro de situação).
     *
     * @return array{ok: int, contingencia: int, total: int}
     */
    #[Computed]
    public function resumo(): array
    {
        $base = collect($this->clientes)->filter(fn (array $cliente): bool => $this->passaTipoEBusca($cliente));
        $ok = $base->filter(fn (array $cliente): bool => $cliente['totalCliente'] === 0)->count();

        return [
            'ok' => $ok,
            'contingencia' => $base->count() - $ok,
            'total' => $base->count(),
        ];
    }

    /**
     * Filtro de tipo e busca, sem o de situação. Com o Help Desk fora do ar o tipo é desconhecido e ninguém é descartado por ele.
     *
     * @param  array{id: int, nome: string, cnpj: ?string, emissao: ?string, totalCliente: int, totalBot: int, totalSactus: int, totalVersatus: int, recente: bool, data: Carbon}  $cliente
     */
    private function passaTipoEBusca(array $cliente): bool
    {
        $tipoOk = ! $this->dadosDoHelp()['ok'] || $this->tipo === '' || $cliente['emissao'] === $this->tipo;

        return $tipoOk && $this->corresponde($this->busca, $cliente);
    }

    /** Busca por nome, CNPJ (com ou sem pontuação) ou ID, sem separar acento e maiúscula.
     *
     * @param  array{id: int, nome: string, cnpj: ?string, emissao: ?string, totalCliente: int, totalBot: int, totalSactus: int, totalVersatus: int, recente: bool, data: Carbon}  $cliente
     */
    private function corresponde(string $termo, array $cliente): bool
    {
        $termo = Str::lower(Str::ascii(trim($termo)));

        if ($termo === '') {
            return true;
        }

        $digitos = preg_replace('/\D/', '', $termo) ?? '';
        $cnpjDigitos = preg_replace('/\D/', '', (string) $cliente['cnpj']) ?? '';

        return str_contains(Str::lower(Str::ascii("#{$cliente['id']} {$cliente['nome']} {$cliente['cnpj']}")), $termo)
            || ($digitos !== '' && str_contains($cnpjDigitos, $digitos));
    }

    /** CNPJ como o Help Desk guarda alguns (12.345.678/0001-90); outros formatos/ambíguos passam como vieram. */
    private static function formatarCnpj(string $cnpj): ?string
    {
        $cnpj = trim($cnpj);

        if ($cnpj === '') {
            return null;
        }

        $digitos = preg_replace('/\D/', '', $cnpj) ?? '';

        if (strlen($digitos) === 14 && strlen($cnpj) === 14) {
            return substr($digitos, 0, 2).'.'.substr($digitos, 2, 3).'.'.substr($digitos, 5, 3)
                .'/'.substr($digitos, 8, 4).'-'.substr($digitos, 12, 2);
        }

        return $cnpj;
    }

    public function render(): View
    {
        return view('livewire.contingencias.dashboard', [
            'ligado' => ConfiguracaoRobo::ligado('contingencias'),
            'permissaoEnv' => ConfiguracaoRobo::permissaoEnv('contingencias'),
            'ativoLocal' => ConfiguracaoRobo::ativoLocal('contingencias'),
        ]);
    }
}
