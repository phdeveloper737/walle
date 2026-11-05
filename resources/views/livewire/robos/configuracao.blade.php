<div class="flex flex-col gap-3">
    @if ($motivoInativo === 'env')
        <x-ui.aviso tipo="erro">
            O robô está desligado neste servidor: {{ $variavelEnv }}=false impede a execução, mesmo com a chave local ligada.
        </x-ui.aviso>
    @elseif ($motivoInativo === 'local')
        <x-ui.aviso>
            O robô está desligado na configuração local (chave "ativo"): ele não executa até a chave ser ligada.
        </x-ui.aviso>
    @endif

    @if ($aviso)
        <x-ui.aviso :tipo="$avisoErro ? 'erro' : 'sucesso'" wire:key="aviso-config-{{ md5($aviso) }}">{{ $aviso }}</x-ui.aviso>
    @endif

    <x-ui.card titulo="Execução do robô" icone="power_settings_new">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
            <div class="min-w-0 flex-1">
                <p class="text-sm text-on-surface">
                    Chave local:
                    <x-ui.etiqueta :cor="$ligadoLocal ? 'sucesso' : 'neutra'">{{ $ligadoLocal ? 'Ligada' : 'Desligada' }}</x-ui.etiqueta>
                </p>
                <p class="mt-1 text-xs text-on-surface-variant">
                    Permissão de implantação ({{ $variavelEnv }}):
                    <x-ui.etiqueta :cor="$permissaoEnv ? 'sucesso' : 'erro'">{{ $permissaoEnv ? 'Ligada' : 'Desligada' }}</x-ui.etiqueta>
                    — o .env continua sendo o corte de segurança.
                </p>
            </div>

            @if ($admin)
                <x-ui.botao variante="contorno" :icone="$ligadoLocal ? 'power_off' : 'power_settings_new'" wire:click="alternarAtivo"
                    wire:loading.attr="disabled" wire:target="alternarAtivo, confirmarSenha">
                    {{ $ligadoLocal ? 'Desligar execução local' : 'Ligar execução local' }}
                </x-ui.botao>
            @endif
        </div>
    </x-ui.card>

    @if ($editando !== null)
        @php $editado = collect($linhas)->firstWhere('chave', $editando); @endphp
        <x-ui.card titulo="Editar parâmetro" icone="edit_note">
            <form wire:submit="salvarParametro" class="flex flex-col gap-3">
                <p class="text-sm text-on-surface">
                    {{ $editado['nome'] ?? $editando }}
                    <span class="text-xs text-on-surface-variant">({{ $editado['unidade'] ?? '' }})</span>
                </p>
                <p class="text-xs text-on-surface-variant">{{ $editado['ajuda'] ?? '' }}</p>

                @if (($editado['tipo'] ?? '') === 'bool')
                    <x-ui.segmentado wire:model.live="valorEditado" :opcoes="['1' => 'Ligado', '0' => 'Desligado']" />
                @elseif (($editado['tipo'] ?? '') === 'array')
                    <x-ui.area-texto label="Valor (JSON)" wire:model="valorEditado" rows="6"
                        ajuda="Lista em JSON, por exemplo [1, 2, 3]. O valor precisa ser uma lista válida." />
                @elseif (in_array($editado['tipo'] ?? '', ['int', 'float'], true))
                    <x-ui.campo label="Valor" tipo="number" wire:model="valorEditado" :ajuda="$editado['ajuda'] ?? null" />
                @else
                    <x-ui.campo label="Valor" wire:model="valorEditado" :ajuda="$editado['ajuda'] ?? null" />
                @endif

                <div class="flex flex-wrap items-center gap-2">
                    <x-ui.botao type="submit" icone="save" wire:loading.attr="disabled" wire:target="salvarParametro, confirmarSenha">Salvar configuração</x-ui.botao>
                    <x-ui.botao variante="texto" wire:click="cancelarEdicao">Cancelar edição</x-ui.botao>
                </div>
            </form>
        </x-ui.card>
    @endif

    <x-ui.card titulo="Parâmetros" icone="tune">
        <x-ui.tabela vazio="Nenhum parâmetro configurável" vazioIcone="tune" largura="900px">
            <x-slot:explicacao>Este robô não tem parâmetros próprios no catálogo.</x-slot:explicacao>
            <x-slot:cabecalho>
                <x-ui.tabela.coluna>Parâmetro</x-ui.tabela.coluna>
                <x-ui.tabela.coluna>Unidade</x-ui.tabela.coluna>
                <x-ui.tabela.coluna>Valor persistido</x-ui.tabela.coluna>
                <x-ui.tabela.coluna>Valor efetivo</x-ui.tabela.coluna>
                @if ($admin)
                    <x-ui.tabela.coluna numero>Ações</x-ui.tabela.coluna>
                @endif
            </x-slot:cabecalho>
            @foreach ($linhas as $linha)
                <x-ui.tabela.linha wire:key="parametro-{{ $linha['chave'] }}">
                    <x-ui.tabela.celula destaque>
                        {{ $linha['nome'] }}
                        <span class="block text-xs font-normal text-on-surface-variant">{{ $linha['ajuda'] }}</span>
                        <span class="block text-xs text-on-surface-variant">{{ $linha['chave'] }}</span>
                    </x-ui.tabela.celula>
                    <x-ui.tabela.celula>{{ $linha['unidade'] }}</x-ui.tabela.celula>
                    <x-ui.tabela.celula>{{ $linha['persistido'] }}</x-ui.tabela.celula>
                    <x-ui.tabela.celula>{{ $linha['efetivo'] }}</x-ui.tabela.celula>
                    @if ($admin)
                        <x-ui.tabela.celula numero>
                            @if ($linha['segredo'])
                                <span class="text-xs text-on-surface-variant">Só no .env</span>
                            @else
                                <x-ui.botao-icone icone="edit" rotulo="Editar {{ $linha['nome'] }}" wire:click="iniciarEdicao('{{ $linha['chave'] }}')" />
                            @endif
                        </x-ui.tabela.celula>
                    @endif
                </x-ui.tabela.linha>
            @endforeach
        </x-ui.tabela>
    </x-ui.card>

    <x-ui.confirmar-senha :aberta="$pedindoSenha" texto="Alterar a configuração do robô muda o que ele faz nos próximos ciclos." />
</div>
