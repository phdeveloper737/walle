<div class="flex flex-col gap-3">
    <p class="text-sm text-on-surface-variant">
        Rotinas do agendador. Esta tela só mostra: nada é disparado daqui. Rotina desligada espera o robô correspondente ser ligado no servidor.
    </p>

    <x-ui.card titulo="Rotinas" icone="schedule">
        <x-ui.tabela vazio="Nenhuma rotina agendada" explicacao="O agendador não tem rotinas cadastradas em routes/console.php." largura="640px">
            <x-slot:cabecalho>
                <x-ui.tabela.coluna>Rotina</x-ui.tabela.coluna>
                <x-ui.tabela.coluna>Frequência</x-ui.tabela.coluna>
                <x-ui.tabela.coluna>Próxima execução</x-ui.tabela.coluna>
                <x-ui.tabela.coluna>Situação</x-ui.tabela.coluna>
            </x-slot:cabecalho>

            @foreach ($rotinas as $rotina)
                <x-ui.tabela.linha wire:key="rotina-{{ $rotina['nome'] }}">
                    <x-ui.tabela.celula destaque>{{ $rotina['nome'] }}</x-ui.tabela.celula>
                    <x-ui.tabela.celula>{{ $rotina['frequencia'] }}</x-ui.tabela.celula>
                    <x-ui.tabela.celula>{{ $rotina['proxima']->format('d/m/Y H:i:s') }}</x-ui.tabela.celula>
                    <x-ui.tabela.celula>
                        <x-ui.etiqueta :cor="$rotina['ligada'] ? 'sucesso' : 'neutra'">{{ $rotina['ligada'] ? 'ligado' : 'desligado' }}</x-ui.etiqueta>
                    </x-ui.tabela.celula>
                </x-ui.tabela.linha>
            @endforeach
        </x-ui.tabela>
    </x-ui.card>
</div>
