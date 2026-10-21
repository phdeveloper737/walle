<?php

namespace App\Livewire\Concerns;

use App\Models\Usuario;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;

/**
 * Ações de admin pedem a senha do Help Desk de novo (vale por alguns minutos na sessão).
 *
 * Uso no componente: const ACOES_COM_SENHA = ['metodo', ...]; e no começo do método:
 *   if (! $this->adminConfirmado('metodo', [$arg])) { return; }
 * Sem a senha confirmada, abre a janela; depois de confirmar, o método é chamado de novo com os mesmos argumentos.
 */
trait ConfirmaSenha
{
    public string $senhaConfirmacao = '';

    public bool $pedindoSenha = false;

    /** Travados: o navegador não pode trocar qual método roda depois da senha */
    #[Locked]
    public ?string $acaoPendente = null;

    /** @var list<int|string> */
    #[Locked]
    public array $argumentosPendentes = [];

    public static function senhaConfirmada(): bool
    {
        $em = session('walle.senha_confirmada_em');

        return is_numeric($em) && (int) $em >= now()->subMinutes((int) config('walle.confirmar_senha_minutos'))->getTimestamp();
    }

    /** @param list<int|string> $argumentos */
    protected function adminConfirmado(string $metodo, array $argumentos = []): bool
    {
        abort_unless(Gate::allows('admin'), 403, 'Só os admins do walle podem fazer isso.');

        if (self::senhaConfirmada()) {
            return true;
        }

        [$this->acaoPendente, $this->argumentosPendentes, $this->pedindoSenha] = [$metodo, $argumentos, true];

        return false;
    }

    public function confirmarSenha(): void
    {
        abort_unless(Gate::allows('admin'), 403);

        $chave = 'walle:confirmar-senha:'.Auth::id();

        if (RateLimiter::tooManyAttempts($chave, 5)) {
            $this->addError('senhaConfirmacao', 'Muitas tentativas. Espere '.RateLimiter::availableIn($chave).' s.');

            return;
        }

        /** @var Usuario $usuario */
        $usuario = Auth::user();

        if (! Auth::getProvider()->validateCredentials($usuario, ['password' => $this->senhaConfirmacao])) {
            RateLimiter::hit($chave, 60);
            $this->senhaConfirmacao = '';
            $this->addError('senhaConfirmacao', 'Senha incorreta.');

            return;
        }

        RateLimiter::clear($chave);
        session()->put('walle.senha_confirmada_em', now()->getTimestamp());

        [$metodo, $argumentos] = [$this->acaoPendente, $this->argumentosPendentes];
        $this->cancelarSenha();

        if ($metodo !== null && in_array($metodo, static::ACOES_COM_SENHA, true)) {
            $this->{$metodo}(...$argumentos);
        }
    }

    public function cancelarSenha(): void
    {
        $this->senhaConfirmacao = '';
        $this->pedindoSenha = false;
        $this->acaoPendente = null;
        $this->argumentosPendentes = [];
        $this->resetErrorBag('senhaConfirmacao');
    }
}
