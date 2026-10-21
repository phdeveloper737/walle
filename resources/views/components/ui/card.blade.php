{{-- Card Material 3. Ex.: <x-ui.card titulo="Banco" icone="database">...</x-ui.card> --}}
@props([
    'titulo' => null,
    'icone' => null,
])

<section {{ $attributes->class('rounded-lg bg-surface-container-high p-4 [--fundo-campo:var(--color-surface-container-high)]') }}>
    @if ($titulo || isset($acoes))
        <header class="mb-4 flex items-center gap-2">
            @if ($icone)
                <x-ui.icone :nome="$icone" class="text-secondary" />
            @endif
            @if ($titulo)
                <h2 class="text-base font-medium text-on-surface">{{ $titulo }}</h2>
            @endif
            @isset($acoes)
                <div class="ml-auto flex items-center gap-2">{{ $acoes }}</div>
            @endisset
        </header>
    @endif

    {{ $slot }}
</section>
