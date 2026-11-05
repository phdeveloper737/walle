{{--
    Janela genérica (Material 3). A tela controla se está aberta; fechar, Esc e clique fora chamam o método Livewire `fechar`.
    Ex.: <x-ui.modal :aberta="$verErros" titulo="Erros do ciclo" icone="error" fechar="fecharErros" largura="640px">
             Conteúdo
             <x-slot:rodape><x-ui.botao variante="texto" wire:click="fecharErros">Fechar</x-ui.botao></x-slot:rodape>
         </x-ui.modal>
--}}
@props([
    'aberta' => false,
    'titulo',
    'icone' => null,
    'fechar',
    'largura' => '560px',
    'rodape' => null,
])

@php($idTitulo = 'modal-titulo-'.md5($fechar.$titulo))

@if ($aberta)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-surface-container-lowest/80 p-4" x-data
        x-on:keydown.escape.window="$wire.{{ $fechar }}()" x-on:click.self="$wire.{{ $fechar }}()">
        <div role="dialog" aria-modal="true" aria-labelledby="{{ $idTitulo }}" tabindex="-1" x-init="$nextTick(() => $el.focus())"
            style="max-width: {{ $largura }}"
            class="flex max-h-full w-full flex-col rounded-xl bg-surface-container-high shadow-xl shadow-black/50 focus:outline-none">
            <div class="flex items-center gap-3 p-6 pb-3">
                @if ($icone)
                    <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-secondary-container text-on-secondary-container">
                        <x-ui.icone :nome="$icone" />
                    </span>
                @endif
                <h2 id="{{ $idTitulo }}" class="min-w-0 flex-1 text-lg text-on-surface">{{ $titulo }}</h2>
                <x-ui.botao-icone icone="close" rotulo="Fechar" x-on:click="$wire.{{ $fechar }}()" />
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto px-6 py-3 text-sm text-on-surface-variant">
                {{ $slot }}
            </div>

            @if ($rodape && ! $rodape->isEmpty())
                <div class="flex justify-end gap-2 p-6 pt-3">
                    {{ $rodape }}
                </div>
            @endif
        </div>
    </div>
@endif
