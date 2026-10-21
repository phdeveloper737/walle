{{-- Etiqueta pequena. cor: neutra (padrão) | primaria | secundaria | erro | sucesso. Ex.: <x-ui.etiqueta cor="secundaria">SRV LOCAL</x-ui.etiqueta> --}}
@props([
    'cor' => 'neutra',
])

@php
    $cores = match ($cor) {
        'primaria' => 'bg-primary-container text-on-primary-container',
        'secundaria' => 'bg-secondary-container text-on-secondary-container',
        'erro' => 'bg-error-container text-on-error-container',
        'sucesso' => 'bg-success-container text-on-success-container',
        default => 'bg-surface-container-highest text-on-surface-variant',
    };
@endphp

<span {{ $attributes->class("inline-flex h-6 shrink-0 items-center gap-1 rounded-sm px-2 text-[11px] font-medium tracking-wide {$cores}") }}>{{ $slot }}</span>
