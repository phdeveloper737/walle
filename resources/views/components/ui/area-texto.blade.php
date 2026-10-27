{{--
    Área de texto Material 3 (várias linhas), no mesmo padrão do <x-ui.campo>.
    O erro de validação aparece sozinho a partir do wire:model (ou do name).
    O valor inicial vem em `valor` (ou no slot) e preserva as quebras de linha.
    Ex.: <x-ui.area-texto label="Observações" wire:model="obs" rows="5" maxlength="500" ajuda="Até 500 caracteres." />
--}}
@props([
    'label',
    'ajuda' => null,
    'id' => null,
    'valor' => null,
])

@php
    $nomeCampo = $attributes->wire('model')->value() ?: $attributes->get('name');
    $id ??= $nomeCampo ?: 'campo-'.\Illuminate\Support\Str::random(6);
    $erro = $nomeCampo ? $errors->first($nomeCampo) : null;
@endphp

<div class="w-full">
    <div class="relative">
        <textarea
            id="{{ $id }}"
            placeholder=" "
            @if ($erro) aria-invalid="true" @endif
            @if ($erro || $ajuda) aria-describedby="{{ $id }}-apoio" @endif
            {{ $attributes->class([
                'peer min-h-28 w-full resize-y rounded-xs border bg-transparent px-4 pt-4 pb-3 text-base text-on-surface outline-none transition placeholder:text-transparent focus:border-2',
                'border-outline hover:border-on-surface focus:border-primary' => ! $erro,
                'border-error focus:border-error' => $erro,
            ]) }}
        >{{ $valor ?? $slot }}</textarea>

        {{-- O fundo do rótulo acompanha a superfície onde o campo está (o card define --fundo-campo) --}}
        <label for="{{ $id }}" @class([
            'pointer-events-none absolute left-3 top-7 -translate-y-1/2 bg-[var(--fundo-campo,var(--color-surface))] px-1 text-base transition-all',
            'peer-focus:top-0 peer-focus:text-xs peer-[:not(:placeholder-shown)]:top-0 peer-[:not(:placeholder-shown)]:text-xs',
            'text-on-surface-variant peer-focus:text-primary' => ! $erro,
            'text-error' => $erro,
        ])>{{ $label }}</label>
    </div>

    @if ($erro || $ajuda)
        <p id="{{ $id }}-apoio" @class(['mt-1 px-4 text-xs', 'text-error' => $erro, 'text-on-surface-variant' => ! $erro])>{{ $erro ?: $ajuda }}</p>
    @endif
</div>
