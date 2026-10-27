@php $clientes = $this->clientes; @endphp

<x-ui.segmentado class="max-w-sm" wire:model.live="consultoria" :opcoes="['S' => 'Consultoria', 'N' => 'Demais clientes']" />

@if ($clientes === null)
    <x-ui.aviso tipo="erro">O Help Desk (192.168.10.254) não respondeu. Atualize a tela em instantes.</x-ui.aviso>
@else
    <x-ui.card titulo="Clientes" icone="groups">
        <p class="mb-2 text-sm text-on-surface-variant">{{ count($clientes) }} {{ count($clientes) === 1 ? 'cliente' : 'clientes' }}</p>

        <x-ui.tabela vazio="Nenhum cliente encontrado" explicacao="Nenhum cliente ativo neste grupo no Help Desk." largura="900px">
            <x-slot:cabecalho>
                <x-ui.tabela.coluna numero>Código</x-ui.tabela.coluna>
                <x-ui.tabela.coluna>Loja</x-ui.tabela.coluna>
                <x-ui.tabela.coluna>Razão social</x-ui.tabela.coluna>
                <x-ui.tabela.coluna>Nome fantasia</x-ui.tabela.coluna>
                <x-ui.tabela.coluna>CNPJ/CPF</x-ui.tabela.coluna>
                <x-ui.tabela.coluna>Contato</x-ui.tabela.coluna>
                <x-ui.tabela.coluna>Telefone</x-ui.tabela.coluna>
                <x-ui.tabela.coluna>E-mail</x-ui.tabela.coluna>
            </x-slot:cabecalho>

            @foreach ($clientes as $c)
                <x-ui.tabela.linha wire:key="cliente-{{ $c->id }}">
                    <x-ui.tabela.celula numero>{{ $c->id }}</x-ui.tabela.celula>
                    <x-ui.tabela.celula>{{ $c->loja }}</x-ui.tabela.celula>
                    <x-ui.tabela.celula destaque>{{ trim((string) $c->razao_social) }}</x-ui.tabela.celula>
                    <x-ui.tabela.celula>{{ trim((string) $c->nome_fantasia) }}</x-ui.tabela.celula>
                    <x-ui.tabela.celula>{{ $c->tipo === 'F' ? $c->cpf : $c->cnpj }}</x-ui.tabela.celula>
                    <x-ui.tabela.celula>{{ $c->contato }}</x-ui.tabela.celula>
                    <x-ui.tabela.celula>{{ $c->telefone ?: $c->celular }}</x-ui.tabela.celula>
                    <x-ui.tabela.celula>{{ $c->email }}</x-ui.tabela.celula>
                </x-ui.tabela.linha>
            @endforeach
        </x-ui.tabela>
    </x-ui.card>
@endif
