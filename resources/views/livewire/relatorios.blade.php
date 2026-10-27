<div class="flex flex-col gap-3">
    <x-ui.abas :abas="\App\Livewire\Relatorios::ABAS" :atual="$aba" />

    @switch($aba)
        @case('contingencia')
            @include('livewire.relatorios.contingencia')
            @break
        @case('motivos')
            @include('livewire.relatorios.motivos')
            @break
        @default
            @include('livewire.relatorios.clientes')
    @endswitch
</div>
