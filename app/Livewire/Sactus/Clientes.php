<?php

namespace App\Livewire\Sactus;

use App\Jobs\ProcessarClienteSactus;
use App\Livewire\Concerns\ConfirmaSenha;
use App\Models\AcaoSactus;
use App\Models\Auditoria;
use App\Models\PainelSactusCfg;
use App\Models\SactusCfgEdicao;
use App\Models\Usuario;
use App\Sactus\AcoesSactus;
use App\Sactus\ArquivoCfg;
use App\Sactus\EdicaoNoTexto;
use App\Sactus\PainelSactus;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Aba Clientes do Sactus: lista com filtros e o detalhe do cliente escolhido
 * (flags, arquivo, conexão, ACL, ERP, pedidos de edição e ações manuais).
 *
 * @property-read list<array<string, mixed>> $todas
 * @property-read list<array<string, mixed>> $linhas
 * @property-read array<string, int> $kpis
 * @property-read PainelSactusCfg|null $selecionado
 * @property-read Collection<int, SactusCfgEdicao> $pedidos
 * @property-read Collection<int, AcaoSactus> $acoes
 */
class Clientes extends Component
{
    use ConfirmaSenha;

    public const ACOES_COM_SENHA = ['revelar', 'revelarArquivo', 'pedirEdicao', 'pedirAcao', 'aplicarAgora'];

    public const DETALHES = ['flags' => 'Flags', 'arquivo' => 'Arquivo', 'conexao' => 'Conexão', 'acl' => 'ACL', 'erp' => 'ERP', 'pedidos' => 'Pedidos e ações'];

    #[Url(except: '')]
    public string $busca = '';

    #[Url(except: 'todos')]
    public string $filtro = 'todos';

    #[Url(as: 'cliente')]
    public ?int $clienteId = null;

    #[Url(as: 'ver', except: 'flags')]
    public string $detalhe = 'flags';

    public string $buscaFlag = '';

    /** @var array<string, string> flag sensível => valor, mostrado a pedido de um admin */
    public array $revelados = [];

    public bool $arquivoCompleto = false;

    public string $edTipo = 'ADICIONAR';

    public string $edFlag = '';

    public string $edValor = '';

    public ?string $aviso = null;

    public bool $avisoErro = false;

    public function updatedFiltro(): void
    {
        if (! array_key_exists($this->filtro, PainelSactus::FILTROS)) {
            $this->filtro = 'todos';
        }
    }

    public function updatedDetalhe(): void
    {
        if (! array_key_exists($this->detalhe, self::DETALHES)) {
            $this->detalhe = 'flags';
        }
    }

    public function selecionar(int $id): void
    {
        $this->clienteId = $id;
        $this->reset('revelados', 'arquivoCompleto', 'edFlag', 'edValor', 'buscaFlag', 'aviso');
        $this->edTipo = 'ADICIONAR';
        $this->resetErrorBag();
    }

    /** Mostra o valor de uma flag sensível (senha, token...) do cliente escolhido. */
    public function revelar(string $flag): void
    {
        if (! $this->adminConfirmado('revelar', [$flag])) {
            return;
        }

        $flags = $this->selecionado?->flags() ?? [];

        if (array_key_exists($flag, $flags)) {
            $this->revelados[$flag] = $flags[$flag];
            Auditoria::registrar('sactus.revelar_flag', $this->clienteId, ['flag' => $flag]);
        }
    }

    public function revelarArquivo(): void
    {
        if (! $this->adminConfirmado('revelarArquivo')) {
            return;
        }

        $this->arquivoCompleto = true;
        Auditoria::registrar('sactus.revelar_arquivo', $this->clienteId);
    }

    /** Registra o pedido; quem aplica no servidor é o robô (no próximo ciclo ou no "aplicar agora"). */
    public function pedirEdicao(): void
    {
        $this->edFlag = trim($this->edFlag);
        $dados = $this->validate([
            'edTipo' => ['required', 'in:ADICIONAR,REMOVER'],
            'edFlag' => ['required', 'regex:'.EdicaoNoTexto::REGEX_FLAG],
            'edValor' => ['required_if:edTipo,ADICIONAR', 'max:'.EdicaoNoTexto::MAX_VALOR,
                fn (string $campo, mixed $valor, \Closure $falha) => EdicaoNoTexto::valorValido((string) $valor) ?: $falha('O valor não pode ter quebra de linha nem caractere de controle.')],
        ], [
            'edFlag.regex' => 'Nome inválido: comece com letra ou _ e use letras, números, ponto, hífen ou _.',
            'edValor.required_if' => 'Informe o valor da flag.',
        ]);

        if (! $this->sactusLigado() || ! $this->adminConfirmado('pedirEdicao')) {
            return;
        }

        $cliente = (int) $this->clienteId;

        if (! PainelSactusCfg::query()->where('fk_c_cliente', $cliente)->exists()) {
            $this->avisar('Cliente sem registro de coleta: o robô não visita esse servidor.', erro: true);

            return;
        }

        $aberto = SactusCfgEdicao::query()->where('fk_c_cliente', $cliente)->whereIn('status', ['PENDENTE', 'PROCESSANDO'])
            ->whereRaw('UPPER(flag) = UPPER(?)', [$dados['edFlag']])->value('id');

        if ($aberto !== null) {
            $this->avisar("Já existe um pedido aberto para essa flag (nº {$aberto}). Espere o robô processar.", erro: true);

            return;
        }

        $pedido = SactusCfgEdicao::create([
            'fk_c_cliente' => $cliente,
            'tipo_edicao' => $dados['edTipo'],
            'flag' => $dados['edFlag'],
            'valor' => $dados['edTipo'] === 'ADICIONAR' ? (string) $dados['edValor'] : '',
            'mensagem' => 'aguardando o proximo ciclo do robo do Sactus',
            'solicitante' => Str::limit(Usuario::nomeDoLogado(), 100, ''),
            'ip' => request()->ip(),
            'criado_em' => now(),
        ]);

        // O valor não vai para a auditoria: pode ser uma senha
        Auditoria::registrar('sactus.pedir_edicao', $cliente, ['pedido' => $pedido->id, 'tipo' => $pedido->tipo_edicao, 'flag' => $pedido->flag]);
        $this->reset('edFlag', 'edValor');
        unset($this->pedidos);
        $this->avisar("Pedido nº {$pedido->id} registrado. O robô aplica no próximo ciclo (ou use “Aplicar agora”).");
    }

    public function pedirAcao(string $acao): void
    {
        if (! $this->sactusLigado() || ! $this->adminConfirmado('pedirAcao', [$acao])) {
            return;
        }

        /** @var Usuario $usuario */
        $usuario = auth()->user();

        try {
            $pedido = app(AcoesSactus::class)->pedir((int) $this->clienteId, $acao, $usuario, request()->ip());
            unset($this->acoes);
            $this->avisar("Ação nº {$pedido->id} na fila: {$pedido->rotulo()}.");
        } catch (InvalidArgumentException $e) {
            $this->avisar($e->getMessage(), erro: true);
        }
    }

    /** Processa só este cliente agora: pedidos pendentes, coleta, ACL e ERP (igual ao ciclo). */
    public function aplicarAgora(): void
    {
        if (! $this->sactusLigado() || ! $this->adminConfirmado('aplicarAgora')) {
            return;
        }

        ProcessarClienteSactus::dispatch((int) $this->clienteId);
        Auditoria::registrar('sactus.aplicar_agora', $this->clienteId);
        $this->avisar('Cliente na fila do robô: em instantes a coleta e os pedidos pendentes são processados.');
    }

    /** @return list<array<string, mixed>> */
    #[Computed]
    public function todas(): array
    {
        return app(PainelSactus::class)->linhas();
    }

    /** @return list<array<string, mixed>> */
    #[Computed]
    public function linhas(): array
    {
        $termo = Str::lower(Str::ascii(trim($this->busca)));
        // CNPJ com ou sem pontuação: só quando o termo é um número de 4 dígitos ou mais
        $digitos = preg_match('/^[\d.\/-]+$/', $termo) ? (string) preg_replace('/\D/', '', $termo) : '';

        return array_values(array_filter($this->todas, fn (array $l): bool => PainelSactus::passa($l, $this->filtro) && (
            $termo === ''
            || str_contains(Str::lower(Str::ascii($l['nome'].' '.$l['cnpj'].' #'.$l['id'].' '.$l['metodo'])), $termo)
            || (strlen($digitos) >= 4 && str_contains((string) preg_replace('/\D/', '', $l['cnpj']), $digitos))
        )));
    }

    /** @return array<string, int> */
    #[Computed]
    public function kpis(): array
    {
        return PainelSactus::kpis($this->todas);
    }

    #[Computed]
    public function selecionado(): ?PainelSactusCfg
    {
        return $this->clienteId === null ? null : PainelSactusCfg::query()->where('fk_c_cliente', $this->clienteId)->first();
    }

    /** @return Collection<int, SactusCfgEdicao> */
    #[Computed]
    public function pedidos(): Collection
    {
        return SactusCfgEdicao::query()->where('fk_c_cliente', $this->clienteId)->latest('id')->limit(10)->get();
    }

    /** @return Collection<int, AcaoSactus> */
    #[Computed]
    public function acoes(): Collection
    {
        return AcaoSactus::query()->where('fk_c_cliente', $this->clienteId)->latest('id')->limit(5)->get();
    }

    public function nomeSelecionado(): string
    {
        foreach ($this->todas as $l) {
            if ($l['id'] === $this->clienteId) {
                return $l['nome'];
            }
        }

        return 'Cliente #'.$this->clienteId;
    }

    public function render(): View
    {
        return view('livewire.sactus.clientes', [
            'sensivel' => ArquivoCfg::sensivel(...),
        ]);
    }

    private function sactusLigado(): bool
    {
        if (config('walle.sactus.ativo')) {
            return true;
        }

        $this->avisar('O Sactus do walle está desligado: use o painel antigo até a virada.', erro: true);

        return false;
    }

    private function avisar(string $texto, bool $erro = false): void
    {
        $this->aviso = $texto;
        $this->avisoErro = $erro;
    }
}
