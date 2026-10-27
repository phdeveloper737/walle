@if (! $admin)
    <x-ui.vazio icone="lock" titulo="Só admins executam SQL nos clientes">
        Peça a um admin do walle para rodar a instrução.
    </x-ui.vazio>
@else
    <x-ui.card titulo="Executar SQL no cliente" icone="play_circle">
        <form wire:submit="executarQuery" class="flex flex-col gap-3">
            <x-ui.campo label="Código do cliente" tipo="number" icone="badge" wire:model="clienteExecucao" min="1"
                ajuda="Número do cliente no Help Desk (c_cliente.id)." />
            <x-ui.area-texto label="Instrução SQL" wire:model="sqlExecucao" rows="8"
                ajuda="Uma instrução por vez. Roda no banco do Sactus do cliente; exige a sua senha. A execução fica na auditoria sem os valores." />
            <div>
                <x-ui.botao type="submit" icone="play_arrow">Executar</x-ui.botao>
            </div>
        </form>
    </x-ui.card>

    @if ($resultadoExecucao === null)
        <x-ui.vazio icone="table_view" titulo="Nenhuma consulta executada">
            O resultado aparece aqui depois que você executar uma instrução.
        </x-ui.vazio>
    @elseif (! $resultadoExecucao['ok'])
        <x-ui.aviso tipo="erro">{{ $resultadoExecucao['mensagem'] }}</x-ui.aviso>
    @else
        <x-ui.card titulo="Resultado" icone="table_view">
            <x-ui.aviso tipo="{{ $resultadoExecucao['truncado'] ? 'info' : 'sucesso' }}">{{ $resultadoExecucao['mensagem'] }}</x-ui.aviso>

            @if ($resultadoExecucao['tipo'] === 'leitura')
                <x-ui.tabela vazio="Nenhuma linha" largura="560px">
                    <x-slot:explicacao>A consulta rodou, mas não devolveu linhas.</x-slot:explicacao>
                    <x-slot:cabecalho>
                        @foreach ($resultadoExecucao['colunas'] as $coluna)
                            <x-ui.tabela.coluna wire:key="col-{{ $loop->index }}">{{ $coluna }}</x-ui.tabela.coluna>
                        @endforeach
                    </x-slot:cabecalho>
                    @foreach ($resultadoExecucao['linhas'] as $linha)
                        <x-ui.tabela.linha wire:key="linha-{{ $loop->index }}">
                            @foreach ($linha as $valor)
                                <x-ui.tabela.celula>{{ $valor ?? 'NULL' }}</x-ui.tabela.celula>
                            @endforeach
                        </x-ui.tabela.linha>
                    @endforeach
                </x-ui.tabela>
            @endif
        </x-ui.card>
    @endif
@endif
