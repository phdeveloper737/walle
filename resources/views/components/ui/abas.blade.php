{{--
    Abas principais (Material 3). abas: [valor => ['rotulo' => ..., 'icone' => ...]]; propriedade: a do Livewire que guarda a aba.
    Ex.: <x-ui.abas :abas="$abas" :atual="$aba" propriedade="aba" />
--}}
@props([
    'abas' => [],
    'atual',
    'propriedade' => 'aba',
])

<div role="tablist" {{ $attributes->class('flex overflow-x-auto border-b border-outline-variant') }}>
    @foreach ($abas as $valor => $aba)
        @php $ativa = $atual === $valor; @endphp
        <button type="button" role="tab" aria-selected="{{ $ativa ? 'true' : 'false' }}" wire:key="aba-{{ $valor }}"
            wire:click="$set('{{ $propriedade }}', '{{ $valor }}')"
            @class([
                'relative flex h-12 shrink-0 items-center gap-2 px-4 text-sm font-medium tracking-[0.1px] transition',
                'before:absolute before:inset-0 before:bg-current before:opacity-0 before:transition-opacity hover:before:opacity-8',
                'text-primary after:absolute after:inset-x-3 after:bottom-0 after:h-[3px] after:rounded-t-full after:bg-primary' => $ativa,
                'text-on-surface-variant' => ! $ativa,
            ])>
            @isset($aba['icone'])
                <x-ui.icone :nome="$aba['icone']" :cheio="$ativa" class="relative text-[20px]" />
            @endisset
            <span class="relative">{{ $aba['rotulo'] }}</span>
        </button>
    @endforeach
</div>
