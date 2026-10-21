<?php

namespace App\Support\HelpDesk;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Senha do banco do Help Desk (192.168.10.254).
 *
 * Mesma regra do sistema antigo (getHelpdeskPass no login.class.php): pergunta à API do Help e,
 * se ela falhar, usa a senha reserva do .env. A senha da API fica no cache (criptografada)
 * para não chamar a API a cada requisição.
 */
class SenhaHelpDesk
{
    private const CHAVE_CACHE = 'help:senha-banco';

    private string $origem = 'cache';

    public function obter(): string
    {
        $guardada = Cache::get(self::CHAVE_CACHE);

        if (is_string($guardada)) {
            $this->origem = 'cache';

            return Crypt::decryptString($guardada);
        }

        $senha = $this->perguntarApi();

        if ($senha === null) {
            // A reserva não vai para o cache: na próxima requisição a API é consultada de novo
            $this->origem = 'reserva';

            return (string) config('database.connections.help.password');
        }

        $this->origem = 'api';
        Cache::put(
            self::CHAVE_CACHE,
            Crypt::encryptString($senha),
            now()->addMinutes((int) config('services.help.senha_cache_minutos', 10)),
        );

        return $senha;
    }

    /** De onde veio a última senha: 'api', 'cache' ou 'reserva'. */
    public function origem(): string
    {
        return $this->origem;
    }

    public function esquecer(): void
    {
        Cache::forget(self::CHAVE_CACHE);
    }

    private function perguntarApi(): ?string
    {
        $token = (string) config('services.help.api_token');

        try {
            $resposta = Http::timeout(5)
                ->withHeaders(['Authorization' => $token, 'X-Authorization' => $token])
                ->acceptJson()
                ->post((string) config('services.help.api_url'), ['type' => 'getPass']);
        } catch (Throwable $e) {
            Log::warning('API do Help Desk indisponível; usando a senha reserva.', ['erro' => $e->getMessage()]);

            return null;
        }

        $senha = $resposta->successful() ? $resposta->json('db_password') : null;

        if (! is_string($senha) || trim($senha) === '') {
            Log::warning('API do Help Desk não devolveu a senha; usando a senha reserva.', ['status' => $resposta->status()]);

            return null;
        }

        return trim($senha);
    }
}
