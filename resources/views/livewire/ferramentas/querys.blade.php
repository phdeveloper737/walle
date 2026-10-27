@if ($admin)
    <x-ui.card :titulo="$editandoId ? 'Editar query' : 'Nova query'" icone="edit_note">
        <form wire:submit="salvar" class="flex flex-col gap-3">
            <x-ui.campo label="Descrição" wire:model="descricao" maxlength="373" />
            <x-ui.area-texto label="Query" wire:model="sql" rows="6" maxlength="{{ \App\Livewire\Ferramentas::LIMITE_SQL }}"
                ajuda="Até {{ \App\Livewire\Ferramentas::LIMITE_SQL }} bytes (acentos contam mais de um). O robô tira crases, aspas duplas e ponto e vírgula antes de rodar." />
            <div class="flex flex-wrap items-center gap-2">
                <x-ui.botao type="submit" icone="save">Salvar query</x-ui.botao>
                @if ($editandoId)
                    <x-ui.botao variante="texto" wire:click="cancelarEdicao">Cancelar edição</x-ui.botao>
                @endif
            </div>
        </form>
    </x-ui.card>
@endif

<x-ui.card titulo="Queries cadastradas" icone="terminal">
    <x-ui.tabela vazio="Nenhuma query cadastrada" largura="560px">
        <x-slot:explicacao>Uma query nova nasce desativada. Cadastre uma e ative para o robô rodá-la nos clientes.</x-slot:explicacao>
        <x-slot:cabecalho>
            <x-ui.tabela.coluna>Descrição</x-ui.tabela.coluna>
            <x-ui.tabela.coluna>Cadastro</x-ui.tabela.coluna>
            <x-ui.tabela.coluna>Situação</x-ui.tabela.coluna>
            @if ($admin)
                <x-ui.tabela.coluna numero>Ações</x-ui.tabela.coluna>
            @endif
        </x-slot:cabecalho>
        @foreach ($this->queries as $query)
            <x-ui.tabela.linha wire:key="query-{{ $query->id }}">
                <x-ui.tabela.celula>{{ $query->descricao }}</x-ui.tabela.celula>
                <x-ui.tabela.celula>{{ $query->data_registro?->format('d/m/Y H:i') ?? '-' }}</x-ui.tabela.celula>
                <x-ui.tabela.celula>
                    <x-ui.etiqueta :cor="$query->ativo === 'S' ? 'sucesso' : 'neutra'">{{ $query->ativo === 'S' ? 'Ativa' : 'Desativada' }}</x-ui.etiqueta>
                </x-ui.tabela.celula>
                @if ($admin)
                    <x-ui.tabela.celula numero>
                        <div class="flex justify-end gap-1">
                            <x-ui.botao-icone icone="edit" rotulo="Editar query" wire:click="editar({{ $query->id }})" />
                            <x-ui.botao-icone :icone="$query->ativo === 'S' ? 'toggle_on' : 'toggle_off'"
                                :rotulo="$query->ativo === 'S' ? 'Desativar query' : 'Ativar query'" wire:click="alternarAtivo({{ $query->id }})" />
                        </div>
                    </x-ui.tabela.celula>
                @endif
            </x-ui.tabela.linha>
        @endforeach
    </x-ui.tabela>
</x-ui.card>
