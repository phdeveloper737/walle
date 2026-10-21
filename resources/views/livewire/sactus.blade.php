<div class="flex flex-col gap-3">
    <x-ui.abas :abas="\App\Livewire\Sactus::ABAS" :atual="$aba" />

    {{-- Na aba Robô o próprio cartão de estado já diz que está desligado --}}
    @if (! config('walle.sactus.ativo') && $aba !== 'robo')
        <x-ui.aviso>
            O Sactus do walle está <strong>desligado</strong> (WALLE_SACTUS_ATIVO=false): os dados abaixo são os da última
            importação e nada daqui encosta nos servidores dos clientes. Pedidos de edição e ações continuam no painel antigo até a virada.
        </x-ui.aviso>
    @endif

    @switch($aba)
        @case('verificador')
            <livewire:sactus.verificador key="aba-verificador" />
            @break
        @case('historico')
            <livewire:sactus.historico key="aba-historico" />
            @break
        @case('robo')
            <livewire:sactus.robo key="aba-robo" />
            @break
        @default
            <livewire:sactus.clientes key="aba-clientes" />
    @endswitch
</div>
