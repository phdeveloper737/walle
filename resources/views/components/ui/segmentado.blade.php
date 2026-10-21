{{--
    Botões segmentados Material 3 (escolha única).
    opcoes: [valor => rótulo]. Ex.: <x-ui.segmentado wire:model.live="filtro" :opcoes="['todos' => 'Todos', 'outros' => 'Outros']" />
--}}
@props([
    'opcoes' => [],
])

@php
    $modelo = $attributes->wire('model')->value() ?: 'segmentado-'.\Illuminate\Support\Str::random(6);
@endphp

<div role="radiogroup" {{ $attributes->only('class')->class('flex h-10 w-full overflow-hidden rounded-full border border-outline') }}>
    @foreach ($opcoes as $valor => $rotulo)
        <label wire:key="seg-{{ $modelo }}-{{ $valor }}"
            class="relative flex flex-1 cursor-pointer items-center justify-center gap-1.5 border-l border-outline px-3 text-sm font-medium text-on-surface transition first:border-l-0 has-checked:bg-secondary-container has-checked:text-on-secondary-container hover:bg-on-surface/8 has-focus-visible:outline-2 has-focus-visible:-outline-offset-2 has-focus-visible:outline-primary">
            <input type="radio" class="peer sr-only" name="{{ $modelo }}" value="{{ $valor }}" {{ $attributes->except('class') }}>
            <x-ui.icone nome="check" class="hidden text-[18px] peer-checked:inline-block" />
            <span class="truncate">{{ $rotulo }}</span>
        </label>
    @endforeach
</div>
