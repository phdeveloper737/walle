<?php

namespace App\Livewire;

use App\Models\PainelCleantaxWorker;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Painel das execuções do CleanTax BOT por cliente (substitui o cleantax.dash.php do sistema antigo).
 * Só leitura. A mensagem sai sem token; a URL e o preview da resposta nunca vão para a tela.
 *
 * @property-read Collection<int, PainelCleantaxWorker> $execucoes
 */
#[Title('CleanTax')]
class CleanTax extends Component
{
    /** @return Collection<int, PainelCleantaxWorker> */
    #[Computed]
    public function execucoes(): Collection
    {
        return PainelCleantaxWorker::query()->orderByDesc('data')->orderBy('fk_c_cliente')->get();
    }

    /** @return array{cor: string, rotulo: string} */
    public function resultado(PainelCleantaxWorker $execucao): array
    {
        return match (strtolower(trim((string) $execucao->status_execucao))) {
            'success' => ['cor' => 'sucesso', 'rotulo' => 'Sucesso'],
            'warning' => ['cor' => 'secundaria', 'rotulo' => 'Atenção'],
            'error' => ['cor' => 'erro', 'rotulo' => 'Erro'],
            'exception' => ['cor' => 'erro', 'rotulo' => 'Exceção'],
            'invalid_url', 'invalid' => ['cor' => 'erro', 'rotulo' => 'URL inválida'],
            default => ['cor' => 'neutra', 'rotulo' => 'Sem status'],
        };
    }

    /** Mensagem pronta para a tela: tira token de query string, cabeçalho Bearer e campos de senha. */
    public function mensagemSegura(PainelCleantaxWorker $execucao): string
    {
        $texto = (string) $execucao->mensagem;
        $texto = preg_replace('/\b(token|access_token|api_key|apikey|senha|password|secret)=[^\s&"\']+/i', '$1=***', $texto) ?? '';
        $texto = preg_replace('/\b(Bearer|Basic)\s+\S+/i', '$1 ***', $texto) ?? '';

        $token = config('walle.cleantax.token');
        if (is_string($token) && $token !== '') {
            $texto = str_replace($token, '***', $texto);
        }

        return $texto;
    }

    public function render(): View
    {
        return view('livewire.clean-tax', ['ligado' => (bool) config('walle.cleantax.ativo')]);
    }
}
