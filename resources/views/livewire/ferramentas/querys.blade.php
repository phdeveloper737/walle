@php $admin = \Illuminate\Support\Facades\Gate::allows('admin'); @endphp

<div class="flex flex-col gap-3">
    @if ($aviso)
        <x-ui.aviso tipo="sucesso">{{ $aviso }}</x-ui.aviso>
    @endif

    @if ($admin)
        <x-ui.card :titulo="$editandoId ? 'Editar query nº '.$editandoId : 'Nova query agendada'" icone="edit_note">
            <form wire:submit="salvar" class="flex flex-col gap-3">
                <x-ui.campo label="Descrição" icone="description" wire:model="descricao" maxlength="373" />
                <x-ui.campo label="Query a executar" icone="code" wire:model="queryExecute" maxlength="3737"
                    ajuda="Roda no banco de cada cliente. O robô tira ponto e vírgula, crase e aspas duplas antes de executar." />
                <div class="flex flex-wrap gap-2">
                    <x-ui.botao type="submit" icone="save">{{ $editandoId ? 'Salvar alterações' : 'Salvar query' }}</x-ui.botao>
                    @if ($editandoId)
                        <x-ui.botao type="button" variante="texto" wire:click="cancelarEdicao">Cancelar edição</x-ui.botao>
                    @endif
                </div>
            </form>
        </x-ui.card>
    @endif

    <x-ui.card titulo="Queries agendadas" icone="schedule">
        <x-ui.tabela vazio="Nenhuma query agendada" vazioIcone="schedule" largura="640px">
            <x-slot:explicacao>
                @if ($admin)
                    Cadastre a primeira no formulário acima. Ela começa desativada.
                @else
                    Só admins cadastram queries.
                @endif
            </x-slot:explicacao>
            <x-slot:cabecalho>
                <x-ui.tabela.coluna numero>Nº</x-ui.tabela.coluna>
                <x-ui.tabela.coluna>Descrição</x-ui.tabela.coluna>
                <x-ui.tabela.coluna>Cadastrada em</x-ui.tabela.coluna>
                <x-ui.tabela.coluna>Situação</x-ui.tabela.coluna>
                @if ($admin)
                    <x-ui.tabela.coluna>Ações</x-ui.tabela.coluna>
                @endif
            </x-slot:cabecalho>
            @foreach ($this->queries as $query)
                <x-ui.tabela.linha wire:key="query-{{ $query->id }}">
                    <x-ui.tabela.celula numero destaque>{{ $query->id }}</x-ui.tabela.celula>
                    <x-ui.tabela.celula>{{ $query->descricao }}</x-ui.tabela.celula>
                    <x-ui.tabela.celula>{{ $query->data_registro?->format('d/m/Y H:i') ?? '—' }}</x-ui.tabela.celula>
                    <x-ui.tabela.celula>
                        @if ($query->ativo === 'S')
                            <x-ui.etiqueta cor="sucesso">Ativa</x-ui.etiqueta>
                        @else
                            <x-ui.etiqueta>Desativada</x-ui.etiqueta>
                        @endif
                    </x-ui.tabela.celula>
                    @if ($admin)
                        <x-ui.tabela.celula>
                            <div class="flex gap-1">
                                <x-ui.botao-icone icone="edit" rotulo="Editar query nº {{ $query->id }}" wire:click="editar({{ $query->id }})" />
                                <x-ui.botao-icone :icone="$query->ativo === 'S' ? 'toggle_on' : 'toggle_off'"
                                    :rotulo="($query->ativo === 'S' ? 'Desativar' : 'Ativar').' query nº '.$query->id"
                                    wire:click="alternarAtivo({{ $query->id }})" />
                            </div>
                        </x-ui.tabela.celula>
                    @endif
                </x-ui.tabela.linha>
            @endforeach
        </x-ui.tabela>
    </x-ui.card>
</div>
