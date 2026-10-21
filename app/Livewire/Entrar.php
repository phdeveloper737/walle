<?php

namespace App\Livewire;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Tela de login: usuário e senha do Help Desk (a_user do 192.168.10.254), como no sistema antigo. */
#[Layout('layouts::convidado')]
#[Title('Entrar')]
class Entrar extends Component
{
    /** Tentativas erradas por usuário + IP antes de bloquear por 1 minuto. */
    public const MAX_TENTATIVAS = 5;

    public string $usuario = '';

    public string $senha = '';

    public function entrar(): void
    {
        $this->usuario = trim($this->usuario);
        $this->senha = trim($this->senha);

        $this->validate(
            ['usuario' => 'required|string|max:100', 'senha' => 'required|string|max:100'],
            ['usuario.required' => 'Informe o usuário.', 'senha.required' => 'Informe a senha.'],
        );

        $chave = 'entrar:'.Str::lower($this->usuario).'|'.request()->ip();

        if (RateLimiter::tooManyAttempts($chave, self::MAX_TENTATIVAS)) {
            event(new Lockout(request()));

            throw ValidationException::withMessages([
                'login' => 'Muitas tentativas. Tente de novo em '.RateLimiter::availableIn($chave).' segundos.',
            ]);
        }

        try {
            $entrou = Auth::attempt(['user' => $this->usuario, 'password' => $this->senha]);
        } catch (QueryException $e) {
            report($e);

            throw ValidationException::withMessages([
                'login' => 'Não foi possível consultar o Help Desk agora. Tente de novo em instantes.',
            ]);
        }

        if (! $entrou) {
            RateLimiter::hit($chave, 60);
            $this->reset('senha');

            throw ValidationException::withMessages(['login' => 'Usuário ou senha inválidos.']);
        }

        RateLimiter::clear($chave);
        session()->regenerate();

        $this->redirectIntended(route('painel'), navigate: true);
    }

    public function render(): View
    {
        return view('livewire.entrar');
    }
}
