<div class="flex flex-col gap-3">
    <x-ui.abas :abas="\App\Livewire\Ferramentas::ABAS" :atual="$aba" />

    @unless ($ligado)
        <x-ui.aviso tipo="erro">
            O robô de tarefas está desligado (WALLE_TAREFAS_ATIVO). As queries ativas só rodam nos clientes depois que ele for ligado.
        </x-ui.aviso>
    @endunless

    @if ($aviso)
        <x-ui.aviso tipo="sucesso" wire:key="aviso-{{ md5($aviso) }}">{{ $aviso }}</x-ui.aviso>
    @endif

    @switch($aba)
        @case('relatorio')
            @include('livewire.ferramentas.relatorio')
            @break
        @case('executar')
            @include('livewire.ferramentas.executar')
            @break
        @default
            @include('livewire.ferramentas.querys')
    @endswitch

    <x-ui.confirmar-senha :aberta="$pedindoSenha" />
</div>
