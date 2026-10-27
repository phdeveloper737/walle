<div class="flex flex-col gap-3">
    @unless (config('walle.tarefas.ativo'))
        <x-ui.aviso>
            O robô das tarefas está <strong>desligado</strong> (WALLE_TAREFAS_ATIVO=false): as queries ficam cadastradas,
            mas nenhuma roda nos clientes até a virada.
        </x-ui.aviso>
    @endunless

    <x-ui.abas :abas="\App\Livewire\Ferramentas::ABAS" :atual="$aba" />

    @switch($aba)
        @case('relatorio')
            @include('livewire.ferramentas.relatorio')
            @break
        @default
            @include('livewire.ferramentas.querys')
    @endswitch

    <x-ui.confirmar-senha :aberta="$pedindoSenha" />
</div>
