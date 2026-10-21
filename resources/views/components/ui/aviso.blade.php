{{-- Aviso na tela. tipo: info (padrão) | sucesso | erro. Ex.: <x-ui.aviso tipo="erro">Usuário ou senha inválidos.</x-ui.aviso> --}}
@props([
    'tipo' => 'info',
])

@php
    [$cores, $icone] = match ($tipo) {
        'erro' => ['bg-error-container text-on-error-container', 'error'],
        'sucesso' => ['bg-success-container text-on-success-container', 'check_circle'],
        default => ['bg-secondary-container text-on-secondary-container', 'info'],
    };
@endphp

<div role="{{ $tipo === 'erro' ? 'alert' : 'status' }}" {{ $attributes->class("flex items-start gap-3 rounded-md px-4 py-3 text-sm {$cores}") }}>
    <x-ui.icone :nome="$icone" class="text-[20px]" />
    <div class="pt-0.5">{{ $slot }}</div>
</div>
