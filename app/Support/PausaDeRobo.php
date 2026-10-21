<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Pausa de um robô, guardada no cache (vale para todos os workers).
 * automatica = o próprio robô se pausou (ex.: consumo indevido na Sefaz); senão foi alguém pelo painel.
 */
final readonly class PausaDeRobo
{
    public function __construct(private string $chaveCache) {}

    /** @return array{ate: Carbon, motivo: string, automatica: bool}|null */
    public function atual(): ?array
    {
        $pausa = Cache::get($this->chaveCache);

        // O Valkey devolve número como texto, por isso is_numeric
        if (! is_array($pausa) || ! is_numeric($pausa['ate'] ?? null) || (int) $pausa['ate'] <= now()->getTimestamp()) {
            return null;
        }

        return [
            'ate' => Carbon::createFromTimestamp((int) $pausa['ate'], config('app.timezone')),
            'motivo' => (string) ($pausa['motivo'] ?? ''),
            'automatica' => (bool) ($pausa['automatica'] ?? false),
        ];
    }

    public function pausar(Carbon $ate, string $motivo = '', bool $automatica = false): void
    {
        Cache::put($this->chaveCache, ['ate' => $ate->getTimestamp(), 'motivo' => $motivo, 'automatica' => $automatica], $ate);
    }

    public function retomar(): void
    {
        Cache::forget($this->chaveCache);
    }
}
