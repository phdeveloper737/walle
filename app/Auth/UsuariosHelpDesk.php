<?php

namespace App\Auth;

use App\Models\Usuario;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;

/**
 * Login pelos usuários do Help Desk, com a mesma regra do sistema antigo (login/api/login.php):
 * usuário e senha (tx_senha) comparados pelo próprio MySQL, só quem não está deletado, o id mais alto.
 *
 * Sem "lembrar de mim": a tabela a_user não tem remember_token e é somente leitura.
 */
class UsuariosHelpDesk implements UserProvider
{
    public function retrieveById($identifier): ?Authenticatable
    {
        return Usuario::query()->ativos()->whereKey($identifier)->first();
    }

    public function retrieveByToken($identifier, #[\SensitiveParameter] $token): ?Authenticatable
    {
        return null;
    }

    public function updateRememberToken(Authenticatable $user, #[\SensitiveParameter] $token): void
    {
        //
    }

    /** @param array<string, mixed> $credentials */
    public function retrieveByCredentials(#[\SensitiveParameter] array $credentials): ?Authenticatable
    {
        if (! is_string($credentials['user'] ?? null) || ! is_string($credentials['password'] ?? null)) {
            return null;
        }

        return Usuario::query()
            ->ativos()
            ->where('user', $credentials['user'])
            ->where('tx_senha', $credentials['password'])
            ->orderByDesc('id')
            ->first();
    }

    /** @param array<string, mixed> $credentials */
    public function validateCredentials(Authenticatable $user, #[\SensitiveParameter] array $credentials): bool
    {
        if (! is_string($credentials['password'] ?? null)) {
            return false;
        }

        // A comparação fica com o MySQL (mesma collation do sistema antigo), não com o PHP
        return Usuario::query()
            ->ativos()
            ->whereKey($user->getAuthIdentifier())
            ->where('tx_senha', $credentials['password'])
            ->exists();
    }

    /** @param array<string, mixed> $credentials */
    public function rehashPasswordIfRequired(Authenticatable $user, #[\SensitiveParameter] array $credentials, bool $force = false): void
    {
        // A senha é do Help Desk: o walle não grava nada no a_user
    }
}
