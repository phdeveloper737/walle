<div class="grid gap-3 lg:grid-cols-2">
    @foreach ($analise['concorrentes'] as $concorrente)
        <x-ui.card :titulo="$concorrente['nome']" icone="storefront" wire:key="ficha-{{ $concorrente['id'] }}">
            <div class="-mt-2 mb-4 flex flex-wrap gap-1.5">
                <x-ui.etiqueta>{{ $concorrente['camada'] }}</x-ui.etiqueta>
                <x-ui.etiqueta>{{ $concorrente['sede'] }}</x-ui.etiqueta>
                <x-ui.etiqueta>{{ $concorrente['escala'] }}</x-ui.etiqueta>
                @if ($concorrente['em_goias'])
                    <x-ui.etiqueta cor="primaria">Atua em Goiás</x-ui.etiqueta>
                @endif
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <h3 class="mb-1.5 text-xs font-medium tracking-wide text-success uppercase">Forças</h3>
                    <ul class="flex flex-col gap-1.5 text-sm text-on-surface">
                        @foreach ($concorrente['forcas'] as $forca)
                            <li class="flex gap-2"><x-ui.icone nome="check_circle" class="text-[18px] text-success" /><span>{{ $forca }}</span></li>
                        @endforeach
                    </ul>
                </div>
                <div>
                    <h3 class="mb-1.5 text-xs font-medium tracking-wide text-error uppercase">Fraquezas</h3>
                    <ul class="flex flex-col gap-1.5 text-sm text-on-surface">
                        @foreach ($concorrente['fraquezas'] as $fraqueza)
                            <li class="flex gap-2"><x-ui.icone nome="cancel" class="text-[18px] text-error" /><span>{{ $fraqueza }}</span></li>
                        @endforeach
                    </ul>
                </div>
            </div>

            <p class="mt-4 flex flex-wrap gap-x-3 gap-y-1 border-t border-outline-variant pt-3 text-xs text-on-surface-variant">
                <span>Fontes:</span>
                @foreach ($concorrente['fontes'] as $fonte)
                    <a href="{{ $fonte['url'] }}" target="_blank" rel="noopener" class="text-primary hover:underline">{{ $fonte['rotulo'] }}</a>
                @endforeach
            </p>
        </x-ui.card>
    @endforeach

    <x-ui.card titulo="No radar" icone="radar">
        <p class="text-sm text-on-surface-variant">Não analisados a fundo: {{ $analise['radar'] }}</p>
    </x-ui.card>
</div>

<x-ui.card titulo="Todas as fontes" icone="link">
    <ul class="grid gap-x-6 gap-y-1.5 text-sm md:grid-cols-2">
        @foreach ($analise['fontes'] as $fonte)
            <li wire:key="fonte-{{ $loop->index }}">
                <a href="{{ $fonte['url'] }}" target="_blank" rel="noopener" class="text-primary hover:underline">{{ $fonte['rotulo'] }}</a>
            </li>
        @endforeach
    </ul>
</x-ui.card>
