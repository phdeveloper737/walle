<?php

namespace App\Livewire;

use App\Conversor\CertificadoInvalido;
use App\Conversor\ConversorCertificado;
use App\Livewire\Concerns\ConfirmaSenha;
use App\Models\Auditoria;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/**
 * Conversor de certificado A1 (.pfx → PEM). Substitui o menu/conversor.php do sistema antigo.
 *
 * Transporte em memória, sem upload: o navegador lê o .pfx com FileReader e chama converter() com o
 * conteúdo em base64 e a senha como argumentos da ação. Nada vira propriedade do componente (o estado
 * volta ao navegador e é reenviado a cada pedido), nada vai para sessão, cache, fila ou log, e o PEM
 * volta só no retorno da ação, que o navegador mostra e baixa gerando o arquivo em memória.
 *
 * Só admin (Gate em cada ação) com a senha do Help Desk confirmada (ConfirmaSenha): o PEM tem a chave
 * privada em aberto. Sem a confirmação, a ação não guarda o .pfx para depois (os argumentos pendentes do
 * trait iriam para o estado do componente); a janela abre com a ação "liberar" e, confirmada a senha, a
 * tela converte de novo com o arquivo que ainda está só no navegador.
 */
#[Title('Conversor de certificado')]
class Conversor extends Component
{
    use ConfirmaSenha;

    public const ACOES_COM_SENHA = ['liberar'];

    public function mount(): void
    {
        abort_unless(Gate::allows('admin'), 403, 'Só os admins do walle podem converter certificados.');
    }

    /** Depois da senha confirmada, avisa a tela para converter o arquivo que ficou no navegador. */
    public function liberar(): void
    {
        if (! $this->adminConfirmado('liberar')) {
            return;
        }

        $this->dispatch('conversor-liberado');
    }

    /**
     * @return array{ok: true, pem: string, titular: string, validoAte: string|null}|array{ok: false, erro: string, pedirSenha?: true}
     */
    public function converter(#[SensitiveParameter] string $pfxBase64, #[SensitiveParameter] string $senha): array
    {
        abort_unless(Gate::allows('admin'), 403, 'Só os admins do walle podem converter certificados.');

        if (! $this->adminConfirmado('liberar')) {
            return ['ok' => false, 'erro' => 'Confirme a sua senha do Help Desk para converter.', 'pedirSenha' => true];
        }

        $this->skipRender();

        try {
            $convertido = app(ConversorCertificado::class)->converter($this->decodificar($pfxBase64), $senha);
        } catch (CertificadoInvalido $falha) {
            Auditoria::registrar('conversor.converter', null, ['resultado' => $falha->motivo]);

            return ['ok' => false, 'erro' => $falha->getMessage()];
        } catch (Throwable $falha) {
            // Só a classe vai para o log: a exceção original pode trazer argumentos no rastro.
            report(new RuntimeException('Falha inesperada ao converter certificado ('.$falha::class.').'));

            return ['ok' => false, 'erro' => 'Não foi possível converter o certificado agora. Tente de novo; se continuar, avise o suporte.'];
        }

        Auditoria::registrar('conversor.converter', null, [
            'resultado' => 'convertido',
            'titular' => $convertido->titular,
            'valido_ate' => $convertido->validoAte,
        ]);

        return ['ok' => true, 'pem' => $convertido->pem(), 'titular' => $convertido->titular, 'validoAte' => $convertido->validoAte];
    }

    /** @throws CertificadoInvalido */
    private function decodificar(#[SensitiveParameter] string $pfxBase64): string
    {
        if ($pfxBase64 === '') {
            throw CertificadoInvalido::vazio();
        }

        // Base64 maior que o de um .pfx no limite nem é decodificado
        if (strlen($pfxBase64) > intdiv(ConversorCertificado::TAMANHO_MAXIMO + 2, 3) * 4) {
            throw CertificadoInvalido::grandeDemais(ConversorCertificado::TAMANHO_MAXIMO);
        }

        $conteudo = base64_decode($pfxBase64, true);

        if ($conteudo === false || $conteudo === '') {
            throw CertificadoInvalido::naoEhPfx();
        }

        return $conteudo;
    }

    public function render(): View
    {
        return view('livewire.conversor', ['tamanhoMaximo' => ConversorCertificado::TAMANHO_MAXIMO]);
    }
}
