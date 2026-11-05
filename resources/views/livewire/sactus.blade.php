<div class="flex flex-col gap-3">
    <x-ui.abas :abas="\App\Livewire\Sactus::ABAS" :atual="$aba" />

    {{-- Na aba Robô o próprio cartão de estado já diz que está desligado --}}
    @if (! $ligado && $aba !== 'robo')
        <x-ui.aviso>
            O Sactus do walle está <strong>desligado</strong> ({{ $motivoDesligado }}): os dados abaixo são os da última
            importação e nada daqui encosta nos servidores dos clientes. Pedidos de edição e ações continuam no painel antigo até a virada.
        </x-ui.aviso>
    @endif

    @switch($aba)
        @case('verificador')
            <livewire:sactus.verificador key="aba-verificador" />
            @break
        @case('pedidos')
            <livewire:sactus.historico key="aba-pedidos" />
            @break
        @case('robo')
            <livewire:sactus.robo key="aba-robo" />
            @break
        @case('configuracao')
            <livewire:robos.configuracao robo="sactus" key="aba-configuracao" />
            @break
        @case('historico')
            <livewire:robos.historico robo="sactus" key="aba-historico" />
            @break
        @default
            <livewire:sactus.clientes key="aba-clientes" />
    @endswitch
</div>
