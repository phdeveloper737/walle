<div class="flex flex-col gap-3">
    <form wire:submit="$refresh" class="flex flex-col gap-3 rounded-lg bg-surface-container-high p-4 md:flex-row md:items-start [--fundo-campo:var(--color-surface-container-high)]">
        <div class="min-w-0 flex-1">
            <x-ui.campo label="Flag" icone="flag" wire:model.live.debounce.400ms="flag" list="flags-todas" autocomplete="off" spellcheck="false" class="font-mono"
                ajuda="Ex.: bloquear_importacao_ibscbs" />
            <datalist id="flags-todas">
                @foreach ($this->nomesDeFlags as $nome)
                    <option value="{{ $nome }}"></option>
                @endforeach
            </datalist>
        </div>
        <div class="min-w-0 flex-1">
            <x-ui.campo label="Valor esperado (opcional)" icone="check" wire:model.live.debounce.400ms="valor" autocomplete="off" spellcheck="false" class="font-mono"
                ajuda="Vazio: só confere se a flag existe." />
        </div>
    </form>

    @if (trim($flag) === '')
        <x-ui.vazio icone="rule" titulo="Escolha uma flag">
            Confere a mesma flag do sactus.cfg em todos os clientes de uma vez, com o valor que você espera.
        </x-ui.vazio>
    @else
        <div class="flex flex-wrap gap-1.5" role="group" aria-label="Filtrar resultado">
            <x-ui.chip :ativo="$situacao === ''" :contagem="count($this->resultado)" wire:click="$set('situacao', '')">Todos</x-ui.chip>
            @foreach (\App\Livewire\Sactus\Verificador::SITUACOES as $chave => $rotulo)
                @if (($contagem[$chave] ?? 0) > 0)
                    <x-ui.chip :ativo="$situacao === $chave" :contagem="$contagem[$chave]" wire:click="$set('situacao', '{{ $chave }}')" wire:key="sit-{{ $chave }}">{{ $rotulo }}</x-ui.chip>
                @endif
            @endforeach
        </div>

        <section class="rounded-lg bg-surface-container-low p-4" aria-label="Resultado">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[520px] text-sm">
                    <thead>
                        <tr class="text-xs text-on-surface-variant">
                            @foreach (['Cliente', 'Situação', 'Valor no cliente'] as $coluna)
                                <th scope="col" class="border-b border-outline-variant px-2 py-1.5 text-left font-medium">{{ $coluna }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($visiveis as $r)
                            <tr class="border-b border-outline-variant/50 last:border-0" wire:key="ver-{{ $r['id'] }}">
                                <td class="px-2 py-1.5">
                                    <a href="{{ route('sactus', ['cliente' => $r['id']]) }}" wire:navigate class="text-on-surface hover:underline">{{ $r['nome'] }}</a>
                                    <span class="text-xs text-on-surface-variant">#{{ $r['id'] }}</span>
                                </td>
                                <td class="px-2 py-1.5">
                                    <x-ui.etiqueta :cor="['confere' => 'sucesso', 'diferente' => 'erro', 'ausente' => 'secundaria'][$r['situacao']] ?? 'neutra'">
                                        {{ \App\Livewire\Sactus\Verificador::SITUACOES[$r['situacao']] }}
                                    </x-ui.etiqueta>
                                </td>
                                <td class="px-2 py-1.5 font-mono text-xs break-all text-on-surface-variant">{{ $r['valor'] === null ? '—' : ($r['valor'] === '' ? '(vazio)' : $r['valor']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
</div>
