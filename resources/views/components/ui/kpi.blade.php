{{-- Número de destaque (stat tile). Ex.: <x-ui.kpi rotulo="Na fila agora" icone="pending" :valor="1284" detalhe="consultadas em até 3 h" /> --}}
@props([
    'rotulo',
    'valor',
    'icone' => null,
    'detalhe' => null,
])

<div {{ $attributes->class('min-w-0 rounded-lg bg-surface-container-low px-4 py-3') }}>
    <p class="flex items-center gap-1.5 text-xs text-on-surface-variant">
        @if ($icone)
            <x-ui.icone :nome="$icone" class="text-[16px]" />
        @endif
        <span class="truncate">{{ $rotulo }}</span>
    </p>
    <p class="mt-1 truncate text-2xl font-medium text-on-surface">{{ is_int($valor) ? \App\Support\Numero::inteiro($valor) : $valor }}</p>
    @if ($detalhe)
        <p class="mt-0.5 truncate text-xs text-on-surface-variant" title="{{ $detalhe }}">{{ $detalhe }}</p>
    @endif
</div>
