<div class="mx-auto flex max-w-5xl flex-col gap-4" wire:poll.30s>
    <p class="text-sm text-on-surface-variant">
        Conferido às {{ $conferidoEm->format('H:i:s') }} · atualiza sozinho a cada 30 segundos.
    </p>

    <div class="grid gap-4 md:grid-cols-2">
        @foreach ($itens as $item)
            <x-ui.card wire:key="saude-{{ $loop->index }}">
                <div class="flex items-start gap-4">
                    <span @class([
                        'flex size-10 shrink-0 items-center justify-center rounded-full',
                        'bg-success-container text-on-success-container' => $item['ok'],
                        'bg-error-container text-on-error-container' => ! $item['ok'],
                    ])>
                        <x-ui.icone :nome="$item['icone']" class="text-[22px]" />
                    </span>

                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <h2 class="font-medium text-on-surface">{{ $item['nome'] }}</h2>
                            <span @class(['text-xs font-medium', 'text-success' => $item['ok'], 'text-error' => ! $item['ok']])>
                                {{ $item['ok'] ? 'ok' : 'com problema' }}
                            </span>
                        </div>
                        <p class="mt-1 text-sm break-words text-on-surface-variant">{{ $item['detalhe'] }}</p>
                    </div>
                </div>
            </x-ui.card>
        @endforeach
    </div>
</div>
