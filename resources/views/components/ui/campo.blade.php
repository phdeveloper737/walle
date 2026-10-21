{{--
    Campo de texto Material 3 (contornado, com rótulo que sobe ao focar/preencher).
    O erro de validação aparece sozinho a partir do wire:model (ou do name).
    Ex.: <x-ui.campo label="Usuário" icone="person" wire:model="usuario" autocomplete="username" />
    Tipo password ganha o botão de mostrar/esconder.
--}}
@props([
    'label',
    'tipo' => 'text',
    'icone' => null,
    'ajuda' => null,
    'id' => null,
])

@php
    $nomeCampo = $attributes->wire('model')->value() ?: $attributes->get('name');
    $id ??= $nomeCampo ?: 'campo-'.\Illuminate\Support\Str::random(6);
    $erro = $nomeCampo ? $errors->first($nomeCampo) : null;
    $senha = $tipo === 'password';
@endphp

<div class="w-full" @if ($senha) x-data="{ ver: false }" @endif>
    <div class="relative">
        @if ($icone)
            <x-ui.icone :nome="$icone" @class([
                'pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-[22px]',
                'text-on-surface-variant' => ! $erro,
                'text-error' => $erro,
            ]) />
        @endif

        <input
            id="{{ $id }}"
            type="{{ $tipo }}"
            @if ($senha) :type="ver ? 'text' : 'password'" @endif
            placeholder=" "
            @if ($erro) aria-invalid="true" @endif
            @if ($erro || $ajuda) aria-describedby="{{ $id }}-apoio" @endif
            {{ $attributes->class([
                'peer h-14 w-full rounded-xs border bg-transparent text-base text-on-surface outline-none transition placeholder:text-transparent focus:border-2',
                'pl-11' => $icone,
                'pl-4' => ! $icone,
                'pr-12' => $senha,
                'pr-4' => ! $senha,
                'border-outline hover:border-on-surface focus:border-primary' => ! $erro,
                'border-error focus:border-error' => $erro,
            ]) }}
        />

        {{-- O fundo do rótulo acompanha a superfície onde o campo está (o card define --fundo-campo) --}}
        <label for="{{ $id }}" @class([
            'pointer-events-none absolute top-1/2 -translate-y-1/2 bg-[var(--fundo-campo,var(--color-surface))] px-1 text-base transition-all',
            'peer-focus:top-0 peer-focus:text-xs peer-[:not(:placeholder-shown)]:top-0 peer-[:not(:placeholder-shown)]:text-xs',
            'left-10' => $icone,
            'left-3' => ! $icone,
            'text-on-surface-variant peer-focus:text-primary' => ! $erro,
            'text-error' => $erro,
        ])>{{ $label }}</label>

        @if ($senha)
            <button type="button" x-on:click="ver = ! ver"
                class="absolute top-1/2 right-2 flex size-10 -translate-y-1/2 items-center justify-center rounded-full text-on-surface-variant transition hover:bg-on-surface/8"
                x-bind:aria-label="ver ? 'Esconder senha' : 'Mostrar senha'">
                <span class="material-symbols-outlined text-[22px]" aria-hidden="true" x-text="ver ? 'visibility_off' : 'visibility'">visibility</span>
            </button>
        @endif
    </div>

    @if ($erro || $ajuda)
        <p id="{{ $id }}-apoio" @class(['mt-1 px-4 text-xs', 'text-error' => $erro, 'text-on-surface-variant' => ! $erro])>{{ $erro ?: $ajuda }}</p>
    @endif
</div>
