<div class="flex flex-col gap-3">
    @if (! $ligado)
        <x-ui.aviso>
            O robô do CleanTax está <strong>desligado</strong> (WALLE_CLEANTAX_ATIVO=false): as execuções abaixo são
            as últimas registradas e nada daqui encosta nos servidores dos clientes.
        </x-ui.aviso>
    @endif

    <x-ui.card titulo="Execuções do CleanTax" icone="cleaning_services">
        <x-ui.tabela vazio="Nenhuma execução ainda" vazioIcone="cleaning_services" largura="900px">
            <x-slot:explicacao>
                A tela começa a ser preenchida quando o robô concluir a primeira execução de um cliente.
            </x-slot:explicacao>
            <x-slot:cabecalho>
                <x-ui.tabela.coluna>Cliente</x-ui.tabela.coluna>
                <x-ui.tabela.coluna>Início</x-ui.tabela.coluna>
                <x-ui.tabela.coluna>Fim</x-ui.tabela.coluna>
                <x-ui.tabela.coluna>Resultado</x-ui.tabela.coluna>
                <x-ui.tabela.coluna numero>HTTP</x-ui.tabela.coluna>
            </x-slot:cabecalho>
            @foreach ($this->execucoes as $execucao)
                @php $resultado = $this->resultado($execucao); $mensagem = $this->mensagemSegura($execucao); @endphp
                <x-ui.tabela.linha wire:key="cleantax-{{ $execucao->fk_c_cliente }}">
                    <x-ui.tabela.celula>
                        {{ filled($execucao->empresa) ? $execucao->empresa : 'Cliente #'.$execucao->fk_c_cliente }}
                        <span class="text-xs text-on-surface-variant">#{{ $execucao->fk_c_cliente }}</span>
                    </x-ui.tabela.celula>
                    <x-ui.tabela.celula>{{ $execucao->data_inicio?->format('d/m/Y H:i') ?? '—' }}</x-ui.tabela.celula>
                    <x-ui.tabela.celula>{{ $execucao->data_fim?->format('d/m/Y H:i') ?? '—' }}</x-ui.tabela.celula>
                    <x-ui.tabela.celula>
                        <x-ui.etiqueta :cor="$resultado['cor']">{{ $resultado['rotulo'] }}</x-ui.etiqueta>
                        @if ($mensagem !== '')
                            <p class="mt-1 text-xs text-on-surface-variant">{{ $mensagem }}</p>
                        @endif
                    </x-ui.tabela.celula>
                    <x-ui.tabela.celula numero>{{ $execucao->http_code ?? '—' }}</x-ui.tabela.celula>
                </x-ui.tabela.linha>
            @endforeach
        </x-ui.tabela>
    </x-ui.card>
</div>
