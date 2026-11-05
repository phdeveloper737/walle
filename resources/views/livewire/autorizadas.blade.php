<div class="flex flex-col gap-3">
    <x-ui.abas :abas="\App\Livewire\Autorizadas::ABAS" :atual="$aba" />

    @switch($aba)
        @case('analise')
            <livewire:autorizadas.analise key="aba-analise" />
            @break
        @case('chave')
            <livewire:autorizadas.chave key="aba-chave" />
            @break
        @case('configuracao')
            <livewire:robos.configuracao robo="autorizadas" key="aba-configuracao" />
            @break
        @case('historico')
            <livewire:robos.historico robo="autorizadas" key="aba-historico" />
            @break
        @default
            <livewire:autorizadas.robo key="aba-robo" />
    @endswitch
</div>
