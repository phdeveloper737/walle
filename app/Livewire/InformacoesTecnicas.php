<?php

namespace App\Livewire;

use App\InformacoesTecnicas\ConfiguracaoTecnica;
use App\InformacoesTecnicas\FalhaGravacaoLocal;
use App\InformacoesTecnicas\FalhaLeituraLocal;
use App\Livewire\Concerns\ConfirmaSenha;
use App\Models\Auditoria;
use App\Models\InformacaoTecnicaPainelContingencias;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Informações técnicas do cliente: acesso ao servidor usado pelo Contingência BOT.
 * Substitui system/consultorias/config do sistema antigo.
 *
 * As senhas nunca chegam à tela: os campos começam vazios e só trocam a senha quando preenchidos.
 *
 * @property-read Collection<int, InformacaoTecnicaPainelContingencias> $clientes
 * @property-read array{dados: array<string, string>|null, erro: bool} $cadastro
 * @property-read array<string, bool> $senhasCadastradas
 */
#[Title('Informações técnicas')]
class InformacoesTecnicas extends Component
{
    use ConfirmaSenha;

    public const ACOES_COM_SENHA = ['salvar', 'importar'];

    /** Cliente escolhido: só muda por selecionar(), nunca direto pelo navegador. */
    #[Locked]
    #[Url(as: 'cliente')]
    public ?int $clienteId = null;

    #[Url(except: '')]
    public string $busca = '';

    /** @var array<string, string> */
    public array $form = [];

    public ?string $aviso = null;

    public bool $avisoErro = false;

    public function mount(): void
    {
        if ($this->clienteId !== null) {
            $this->selecionar($this->clienteId);
        }
    }

    public function selecionar(int $id): void
    {
        $this->aviso = null;
        $this->avisoErro = false;
        $this->resetErrorBag();

        if (InformacaoTecnicaPainelContingencias::where('fk_c_cliente', $id)->where('deletado', 'N')->doesntExist()) {
            $this->clienteId = null;
            $this->form = [];

            return;
        }

        $this->clienteId = $id;
        $this->carregarFormulario();
    }

    /** Grava a configuração do cliente escolhido (admin, com a senha confirmada). */
    public function salvar(): void
    {
        abort_unless(Gate::allows('admin'), 403, 'Só os admins do walle podem fazer isso.');

        if ($this->clienteId === null) {
            return;
        }

        $servico = app(ConfiguracaoTecnica::class);
        $this->validate($servico->regras('form.'), $servico->mensagens(), $servico->rotulos('form.'));

        if (! $this->adminConfirmado('salvar')) {
            return;
        }

        $alterados = $servico->camposAlterados($this->clienteId, $this->form);

        try {
            $servico->salvar($this->clienteId, $this->form);
        } catch (FalhaGravacaoLocal) {
            $this->avisarFalhaDeGravacao();

            return;
        }

        Auditoria::registrar('informacoes_tecnicas.salvar', $this->clienteId, ['campos' => $alterados]);

        $this->carregarFormulario();
        $this->avisoErro = false;
        $this->aviso = 'Configuração salva.';
    }

    /** Traz os dados técnicos do Help Desk (só leitura lá) para o walle, mantendo tipo de servidor, de PDV e emissão de cupons. */
    public function importar(): void
    {
        abort_unless(Gate::allows('admin'), 403, 'Só os admins do walle podem fazer isso.');

        if ($this->clienteId === null || ! $this->adminConfirmado('importar')) {
            return;
        }

        try {
            app(ConfiguracaoTecnica::class)->importarDoHelpDesk($this->clienteId);
        } catch (ValidationException $e) {
            $this->avisoErro = true;
            $this->aviso = $e->validator->errors()->first();

            return;
        } catch (FalhaGravacaoLocal) {
            $this->avisarFalhaDeGravacao();

            return;
        } catch (FalhaLeituraLocal) {
            $this->avisoErro = true;
            $this->aviso = 'Não foi possível ler a configuração do cliente no banco do walle. Tente de novo em instantes.';

            return;
        } catch (QueryException $e) {
            // Só a leitura do Help Desk chega aqui (a local vira FalhaLeituraLocal) e a consulta leva só o id.
            report($e);
            $this->avisoErro = true;
            $this->aviso = 'O Help Desk (192.168.10.254) não respondeu. Nada foi importado; tente de novo em instantes.';

            return;
        }

        Auditoria::registrar('informacoes_tecnicas.importar', $this->clienteId, ['origem' => 'help_desk']);

        $this->carregarFormulario();
        $this->avisoErro = false;
        $this->aviso = 'Configuração importada do Help Desk.';
    }

    /** @return Collection<int, InformacaoTecnicaPainelContingencias> */
    #[Computed]
    public function clientes(): Collection
    {
        $termo = trim($this->busca);

        return InformacaoTecnicaPainelContingencias::query()
            ->where('deletado', 'N')
            ->when($termo !== '', fn ($consulta) => $consulta->where(fn ($q) => $q
                ->where('razao_social', 'like', "%{$termo}%")
                ->orWhere('nome_fantasia', 'like', "%{$termo}%")
                ->orWhere('cnpj', 'like', "%{$termo}%")
                ->orWhere('fk_c_cliente', ctype_digit($termo) ? (int) $termo : 0)))
            ->orderBy('razao_social')
            ->get(['id', 'fk_c_cliente', 'razao_social', 'nome_fantasia', 'cnpj']);
    }

    /**
     * Cadastro do cliente no Help Desk (ao vivo, só leitura). dados = null quando o Help Desk não respondeu.
     *
     * @return array{dados: array<string, string>|null, erro: bool}
     */
    #[Computed]
    public function cadastro(): array
    {
        if ($this->clienteId === null) {
            return ['dados' => null, 'erro' => false];
        }

        try {
            $linha = DB::connection('help')->table('c_cliente as c')
                ->leftJoin('informacao_tecnica_dataserver as i', 'i.fk_c_cliente', '=', 'c.id')
                ->where('c.id', $this->clienteId)
                ->first(['c.id', 'c.cnpj', 'c.razao_social', 'c.nome_fantasia', 'i.loja']);
        } catch (QueryException $e) {
            report($e);

            return ['dados' => null, 'erro' => true];
        }

        if ($linha === null) {
            return ['dados' => null, 'erro' => false];
        }

        return ['dados' => [
            'fk_c_cliente' => (string) $linha->id,
            'cnpj' => (string) $linha->cnpj,
            'id_loja' => (string) $linha->loja,
            'razao_social' => (string) $linha->razao_social,
            'nome_fantasia' => (string) $linha->nome_fantasia,
        ], 'erro' => false];
    }

    /**
     * Só informa se há senha gravada; o valor nunca sai do servidor.
     *
     * @return array<string, bool>
     */
    #[Computed]
    public function senhasCadastradas(): array
    {
        $linha = $this->clienteId === null ? null : InformacaoTecnicaPainelContingencias::find($this->clienteId);
        $cadastradas = [];

        foreach (array_keys(ConfiguracaoTecnica::SEGREDOS) as $segredo) {
            // Qualquer valor não vazio conta como cadastrada, inclusive só espaços; o valor nunca vai para a tela.
            $cadastradas[$segredo] = $linha !== null && (string) $linha->getAttribute($segredo) !== '';
        }

        return $cadastradas;
    }

    /** Sem report(): a exceção original leva o SQL com as senhas, então nada dela vai para o log. */
    private function avisarFalhaDeGravacao(): void
    {
        $this->avisoErro = true;
        $this->aviso = 'Não foi possível gravar no banco do walle. Nada foi alterado; confira o tamanho dos valores e tente de novo.';
    }

    private function carregarFormulario(): void
    {
        $linha = $this->clienteId === null ? null : InformacaoTecnicaPainelContingencias::find($this->clienteId);
        $form = [];

        foreach (array_keys(ConfiguracaoTecnica::CAMPOS) as $campo) {
            $form[$campo] = (string) ($linha?->getAttribute($campo) ?? '');
        }

        foreach (array_keys(ConfiguracaoTecnica::SEGREDOS) as $segredo) {
            $form[$segredo] = '';
        }

        $this->form = $form;
        unset($this->senhasCadastradas, $this->cadastro, $this->clientes);
    }

    public function render(): View
    {
        return view('livewire.informacoes-tecnicas');
    }
}
