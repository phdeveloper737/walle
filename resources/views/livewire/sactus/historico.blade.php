<div class="flex flex-col gap-3">
    <div class="flex flex-wrap gap-1.5" role="group" aria-label="Filtrar por situação">
        <x-ui.chip :ativo="$status === ''" wire:click="$set('status', '')">Todos</x-ui.chip>
        @foreach (\App\Livewire\Sactus\Historico::STATUS as $chave => $rotulo)
            <x-ui.chip :ativo="$status === $chave" wire:click="$set('status', '{{ $chave }}')" wire:key="st-{{ $chave }}">{{ $rotulo }}</x-ui.chip>
        @endforeach
    </div>

    <div class="grid gap-3 xl:grid-cols-2" wire:loading.class="opacity-60" wire:target="status">
        <section class="rounded-lg bg-surface-container-low p-4" aria-label="Pedidos de edição">
            <h2 class="font-medium text-on-surface">Pedidos de edição do sactus.cfg</h2>
            <p class="text-xs text-on-surface-variant">O painel registra; o robô aplica no servidor do cliente (com backup e conferência).</p>

            @forelse ($this->pedidos as $p)
                <div class="mt-2 rounded-md bg-surface-container p-3 text-sm" wire:key="hp-{{ $p->id }}">
                    <p class="flex flex-wrap items-center gap-2">
                        <x-ui.etiqueta :cor="['CONCLUIDO' => 'sucesso', 'ERRO' => 'erro', 'PROCESSANDO' => 'primaria'][$p->status] ?? 'neutra'">{{ ucfirst(strtolower($p->status)) }}</x-ui.etiqueta>
                        <a href="{{ route('sactus', ['cliente' => $p->fk_c_cliente, 'ver' => 'pedidos']) }}" wire:navigate class="text-on-surface hover:underline">{{ $nomes[$p->fk_c_cliente] ?? 'Cliente #'.$p->fk_c_cliente }}</a>
                    </p>
                    <p class="mt-1 font-mono text-xs break-all text-on-surface">
                        {{ $p->tipo_edicao === 'REMOVER' ? '− ' : '+ ' }}{{ $p->flag }}{{ $p->tipo_edicao === 'ADICIONAR' ? '='.($sensivel($p->flag) ? '••••••' : $p->valor) : '' }}
                    </p>
                    <p class="mt-1 text-xs text-on-surface-variant">nº {{ $p->id }} · {{ $p->solicitante ?: '—' }} · {{ $p->criado_em->format('d/m/Y H:i') }}
                        @if ($p->processado_em) · processado {{ $p->processado_em->format('d/m H:i') }} @endif · {{ $p->tentativas }} tentativa(s)</p>
                    @if ($p->mensagem)
                        <p class="mt-1 text-xs break-words text-on-surface-variant">{{ $p->mensagem }}</p>
                    @endif
                </div>
            @empty
                <x-ui.vazio icone="edit_note" titulo="Nenhum pedido" class="py-8" />
            @endforelse
        </section>

        <section class="rounded-lg bg-surface-container-low p-4" aria-label="Ações manuais">
            <h2 class="font-medium text-on-surface">Ações manuais</h2>
            <p class="text-xs text-on-surface-variant">yum update e instalação do pacote acl, pedidos por um admin.</p>

            @forelse ($this->acoes as $a)
                <div class="mt-2 rounded-md bg-surface-container p-3 text-sm" wire:key="ha-{{ $a->id }}">
                    <p class="flex flex-wrap items-center gap-2">
                        <x-ui.etiqueta :cor="['CONCLUIDO' => 'sucesso', 'ERRO' => 'erro', 'PROCESSANDO' => 'primaria'][$a->status] ?? 'neutra'">{{ ucfirst(strtolower($a->status)) }}</x-ui.etiqueta>
                        <span class="text-on-surface">{{ $a->rotulo() }}</span>
                    </p>
                    <p class="mt-1 text-xs text-on-surface-variant">
                        <a href="{{ route('sactus', ['cliente' => $a->fk_c_cliente, 'ver' => 'pedidos']) }}" wire:navigate class="hover:underline">{{ $nomes[$a->fk_c_cliente] ?? 'Cliente #'.$a->fk_c_cliente }}</a>
                        · nº {{ $a->id }} · {{ $a->solicitante }} · {{ $a->criado_em->format('d/m/Y H:i') }} @if ($a->rc !== null) · rc {{ $a->rc }} @endif
                    </p>
                    @if ($a->erro)
                        <p class="mt-1 text-xs break-words text-error">{{ $a->erro }}</p>
                    @endif
                </div>
            @empty
                <x-ui.vazio icone="terminal" titulo="Nenhuma ação" class="py-8" />
            @endforelse
        </section>
    </div>
</div>
