<?php

namespace App\Livewire\Robos;

use App\Livewire\Concerns\ConfirmaSenha;
use App\Models\Usuario;
use App\Support\ConfiguracaoRobo;
use App\Support\ParametroCatalogo;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Aba Configuração de um robô: parâmetros do catálogo (valor salvo e valor efetivo), motivo de o robô não rodar
 * e, só para admin com senha, edição dos parâmetros e a chave geral "ativo". Segredos nunca chegam à tela.
 * A mudança vale a partir do próximo ciclo; um ciclo em andamento não muda.
 */
class Configuracao extends Component
{
    use ConfirmaSenha;

    public const ACOES_COM_SENHA = ['salvarParametro', 'alternarAtivo'];

    #[Locked]
    public string $robo = '';

    #[Locked]
    public ?string $editando = null;

    public string $valorEditado = '';

    public ?string $aviso = null;

    public bool $avisoErro = false;

    public function mount(string $robo): void
    {
        $catalogo = ParametroCatalogo::todos();
        if (! isset($catalogo[$robo])) {
            abort(404);
        }
        $this->robo = $robo;
    }

    public function iniciarEdicao(string $parametro): void
    {
        if (! Gate::allows('admin')) {
            abort(403);
        }

        $catalogo = ParametroCatalogo::todos();
        // A chave geral "ativo" tem botão próprio; segredos nunca abrem para edição
        if ($parametro === 'ativo' || ! isset($catalogo[$this->robo][$parametro])) {
            return;
        }

        $info = $catalogo[$this->robo][$parametro];
        if (($info['fronteira']['segredo'] ?? false) === true) {
            return;
        }

        $this->editando = $parametro;
        $efetivo = ConfiguracaoRobo::efetivo($this->robo, $parametro);

        $this->valorEditado = match (true) {
            is_array($efetivo) => json_encode($efetivo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '',
            is_bool($efetivo) => $efetivo ? '1' : '0',
            default => (string) ($efetivo ?? ''),
        };

        $this->aviso = null;
    }

    public function cancelarEdicao(): void
    {
        $this->reset('editando', 'valorEditado');
    }

    public function salvarParametro(): void
    {
        if (! $this->adminConfirmado('salvarParametro')) {
            return;
        }

        if ($this->editando === null) {
            return;
        }

        $catalogo = ParametroCatalogo::todos();
        $info = $catalogo[$this->robo][$this->editando] ?? null;
        if (! $info) {
            return;
        }

        $tipo = $info['fronteira']['tipo'] ?? 'string';

        try {
            $valorParaGravar = match ($tipo) {
                'int', 'float' => trim($this->valorEditado),
                'bool' => $this->valorEditado === '1',
                'array' => json_decode($this->valorEditado, true),
                default => $this->valorEditado,
            };

            if ($tipo === 'array' && ! is_array($valorParaGravar)) {
                throw new \InvalidArgumentException('Lista inválida');
            }

            ConfiguracaoRobo::gravar($this->robo, $this->editando, $valorParaGravar, Usuario::nomeDoLogado());

            $this->aviso = 'Configuração salva.';
            $this->avisoErro = false;
            $this->cancelarEdicao();
        } catch (\InvalidArgumentException|\LogicException $e) {
            $this->aviso = match ($e->getMessage()) {
                'Fora da fronteira' => 'O valor está fora dos limites deste parâmetro. Confira o mínimo e o máximo e salve de novo.',
                'Tipo inválido' => 'O valor não tem o formato esperado. Corrija e salve de novo.',
                'Lista inválida' => 'A lista precisa estar em JSON válido, por exemplo [1, 2, 3]. Corrija e salve de novo.',
                default => 'Não foi possível salvar a configuração.',
            };
            $this->avisoErro = true;
        } catch (QueryException $e) {
            report($e);
            $this->aviso = 'Não foi possível salvar a configuração agora. Tente de novo em instantes.';
            $this->avisoErro = true;
        }
    }

    public function alternarAtivo(): void
    {
        if (! $this->adminConfirmado('alternarAtivo')) {
            return;
        }

        $atual = ConfiguracaoRobo::ativoLocal($this->robo);
        $novo = ! $atual;

        ConfiguracaoRobo::definirAtivo($this->robo, $novo, Usuario::nomeDoLogado());

        $this->aviso = $novo ? 'Execução local ligada.' : 'Execução local desligada.';
        $this->avisoErro = false;
    }

    public function render(): View
    {
        $catalogo = ParametroCatalogo::todos();
        $parametros = $catalogo[$this->robo] ?? [];
        $motivo = ConfiguracaoRobo::motivoInativo($this->robo);

        $linhas = [];
        foreach ($parametros as $chave => $info) {
            if ($chave === 'ativo') {
                continue;
            }

            $segredo = (bool) ($info['fronteira']['segredo'] ?? false);

            $linhas[] = [
                'chave' => $chave,
                'nome' => $info['nome'],
                'unidade' => $info['unidade'],
                'ajuda' => $info['ajuda'],
                'tipo' => $info['fronteira']['tipo'] ?? 'string',
                'segredo' => $segredo,
                'persistido' => $segredo ? 'Definido no .env' : $this->formatar(ConfiguracaoRobo::persistido($this->robo, $chave)),
                'efetivo' => $segredo ? '••••••' : $this->formatar(ConfiguracaoRobo::efetivo($this->robo, $chave)),
            ];
        }

        return view('livewire.robos.configuracao', [
            'linhas' => $linhas,
            'ligadoLocal' => ConfiguracaoRobo::ativoLocal($this->robo),
            'permissaoEnv' => ConfiguracaoRobo::permissaoEnv($this->robo),
            'motivoInativo' => $motivo,
            'variavelEnv' => 'WALLE_'.strtoupper($this->robo).'_ATIVO',
            'admin' => Gate::allows('admin'),
        ]);
    }

    private function formatar(mixed $valor): string
    {
        return match (true) {
            $valor === null => '—',
            is_bool($valor) => $valor ? 'Ligado' : 'Desligado',
            is_array($valor) => json_encode($valor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '—',
            default => (string) $valor,
        };
    }
}
