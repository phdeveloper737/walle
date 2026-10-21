{{--
    Botões segmentados Material 3 (escolha única).
    opcoes: [valor => rótulo]. compacto: sem o ✓ e com letra menor, para espaços estreitos.
    Ex.: <x-ui.segmentado wire:model.live="filtro" :opcoes="['todos' => 'Todos', 'outros' => 'Outros']" />
--}}
@props([
    'opcoes' => [],
    'compacto' => false,
])

@php
    $modelo = $attributes->wire('model')->value() ?: 'segmentado-'.\Illuminate\Support\Str::random(6);
@endphp

<div role="radiogroup" {{ $attributes->only('class')->class('flex h-10 w-full overflow-hidden rounded-full border border-outline') }}>
    @foreach ($opcoes as $valor => $rotulo)
        <label wire:key="seg-{{ $modelo }}-{{ $valor }}" @class([
            'relative flex min-w-0 cursor-pointer items-center justify-center gap-1.5 border-l border-outline font-medium text-on-surface transition first:border-l-0',
            'has-checked:bg-secondary-container has-checked:text-on-secondary-container hover:bg-on-surface/8',
            'has-focus-visible:outline-2 has-focus-visible:-outline-offset-2 has-focus-visible:outline-primary',
            'flex-1 px-3 text-sm' => ! $compacto,
            'flex-auto px-2 text-xs' => $compacto,
        ])>
            <input type="radio" class="peer sr-only" name="{{ $modelo }}" value="{{ $valor }}" {{ $attributes->except('class') }}>
            @unless ($compacto)
                <x-ui.icone nome="check" class="hidden text-[18px] peer-checked:inline-block" />
            @endunless
            <span class="truncate">{{ $rotulo }}</span>
        </label>
    @endforeach
</div>
