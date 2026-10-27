@php
    use App\InformacoesTecnicas\ConfiguracaoTecnica;

    $admin = \Illuminate\Support\Facades\Gate::allows('admin');
    $docker = ($form['tips_server'] ?? '') === 'DO';
    $cadastro = $this->cadastro;
    $opcoesDocker = ['' => 'Nenhum', ...array_combine(ConfiguracaoTecnica::SERVIDORES_DOCKER, ConfiguracaoTecnica::SERVIDORES_DOCKER)];
    $simNao = ['S' => 'Sim', 'N' => 'Não'];
@endphp

<div class="flex flex-col gap-3 lg:flex-row lg:items-start">
    {{-- Clientes com configuração técnica --}}
    <section class="flex w-full flex-col gap-2 lg:sticky lg:top-17 lg:w-[340px] lg:shrink-0 2xl:w-[380px]" aria-label="Clientes">
        <x-ui.busca placeholder="Buscar cliente, CNPJ ou #id" wire:model.live.debounce.250ms="busca" />

        <p class="px-1 text-xs text-on-surface-variant">
            {{ $this->clientes->count() }} {{ $this->clientes->count() === 1 ? 'cliente' : 'clientes' }}
        </p>

        <div class="flex flex-col gap-1.5 transition-opacity lg:max-h-[calc(100vh-13.5rem)] lg:overflow-y-auto lg:pr-1"
            wire:loading.class="opacity-60" wire:target="busca">
            @forelse ($this->clientes as $item)
                @php $selecionado = $item->fk_c_cliente === $clienteId; @endphp
                <button type="button" wire:key="cliente-{{ $item->fk_c_cliente }}" wire:click="selecionar({{ $item->fk_c_cliente }})"
                    wire:loading.attr="disabled" wire:target="selecionar"
                    x-on:click="if (window.innerWidth < 1024) setTimeout(() => document.getElementById('detalhe-configuracao')?.scrollIntoView({ behavior: 'smooth' }), 200)"
                    @if ($selecionado) aria-current="true" @endif
                    @class([
                        'relative w-full shrink-0 overflow-hidden rounded-md px-3 py-2.5 text-left transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:cursor-wait',
                        'before:absolute before:inset-0 before:bg-on-surface before:opacity-0 before:transition-opacity hover:before:opacity-8',
                        'bg-secondary-container text-on-secondary-container' => $selecionado,
                        'bg-surface-container-high' => ! $selecionado,
                    ])>
                    <span class="relative block font-medium break-words">{{ $item->razao_social }}</span>
                    <span @class(['relative mt-1.5 flex flex-wrap items-center gap-1.5 text-xs', 'text-on-surface-variant' => ! $selecionado])>
                        <span>#{{ $item->fk_c_cliente }}</span>
                        @if ($item->cnpj)
                            <span aria-hidden="true">·</span>
                            <span>{{ $item->cnpj }}</span>
                        @endif
                    </span>
                </button>
            @empty
                @if (trim($busca) !== '')
                    <x-ui.vazio icone="search_off" titulo="Nenhum cliente encontrado">Tente outro termo de busca.</x-ui.vazio>
                @else
                    <x-ui.vazio icone="settings_ethernet" titulo="Nenhum cliente configurado">
                        Ainda não há cliente com informação técnica no walle. Eles aparecem aqui depois da importação do Help Desk.
                    </x-ui.vazio>
                @endif
            @endforelse
        </div>
    </section>

    {{-- Configuração do cliente escolhido --}}
    <section id="detalhe-configuracao" class="min-w-0 flex-1 scroll-mt-16 rounded-lg bg-surface-container-low p-3 sm:p-4" aria-label="Configuração do cliente">
        @if ($clienteId === null)
            <x-ui.vazio icone="storefront" titulo="Escolha um cliente">
                Selecione um cliente na lista para ver e alterar o acesso técnico ao servidor dele.
            </x-ui.vazio>
        @else
            <header class="border-b border-outline-variant pb-3">
                <p class="text-sm text-secondary">Cliente #{{ $clienteId }}</p>
                <h2 class="text-2xl text-on-surface">{{ $cadastro['dados']['razao_social'] ?? $this->clientes->firstWhere('fk_c_cliente', $clienteId)?->razao_social }}</h2>
            </header>

            @if ($aviso)
                <x-ui.aviso :tipo="$avisoErro ? 'erro' : 'sucesso'" class="mt-3">{{ $aviso }}</x-ui.aviso>
            @endif

            @if ($cadastro['erro'])
                <x-ui.aviso tipo="erro" class="mt-3">O Help Desk (192.168.10.254) não respondeu. Os dados do cadastro não aparecem; a configuração abaixo é a gravada no walle.</x-ui.aviso>
            @endif

            <form wire:submit="salvar" class="mt-3 flex flex-col gap-3">
                <x-ui.card titulo="Cadastro no Help Desk" icone="badge">
                    <p class="mb-3 text-xs text-on-surface-variant">Só leitura: para mudar, altere o cadastro no Help Desk e importe.</p>
                    <div class="grid gap-3 sm:grid-cols-2">
                        @foreach (['fk_c_cliente' => 'Cliente', 'id_loja' => 'Loja', 'cnpj' => 'CNPJ', 'razao_social' => 'Razão social', 'nome_fantasia' => 'Nome fantasia'] as $nome => $rotulo)
                            <x-ui.campo :label="$rotulo" :id="'cadastro-'.$nome" readonly :value="$cadastro['dados'][$nome] ?? ''" />
                        @endforeach
                    </div>
                </x-ui.card>

                <x-ui.card titulo="Servidor e PDV" icone="dns">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="flex flex-col gap-1 sm:col-span-2">
                            <p class="text-sm text-on-surface-variant">Cliente ativo no painel de contingências</p>
                            <x-ui.segmentado wire:model="form.ativo_painel_contingencias" :opcoes="$simNao" />
                        </div>
                        <div class="flex flex-col gap-1 sm:col-span-2">
                            <p class="text-sm text-on-surface-variant">Tipo de servidor</p>
                            <x-ui.segmentado wire:model.live="form.tips_server" :opcoes="ConfiguracaoTecnica::TIPOS_SERVIDOR" />
                            @error('form.tips_server') <p class="px-1 text-xs text-error">{{ $message }}</p> @enderror
                        </div>
                        <div class="flex flex-col gap-1 sm:col-span-2">
                            <p class="text-sm text-on-surface-variant">Tipo de PDV</p>
                            <x-ui.segmentado wire:model="form.tips_pdv" :opcoes="ConfiguracaoTecnica::TIPOS_PDV" />
                            @error('form.tips_pdv') <p class="px-1 text-xs text-error">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </x-ui.card>

                @if ($docker)
                    <x-ui.card titulo="Docker" icone="deployed_code">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="flex flex-col gap-1">
                                <p class="text-sm text-on-surface-variant">Servidor principal (Docker)</p>
                                <x-ui.segmentado compacto wire:model="form.ip_docker_principal" :opcoes="$opcoesDocker" />
                                @error('form.ip_docker_principal') <p class="px-1 text-xs text-error">{{ $message }}</p> @enderror
                            </div>
                            <div class="flex flex-col gap-1">
                                <p class="text-sm text-on-surface-variant">Banco separado (Docker)</p>
                                <x-ui.segmentado wire:model="form.banco_separado_docker" :opcoes="$simNao" />
                            </div>
                            <x-ui.campo label="IP do contêiner" icone="lan" wire:model="form.ip_container_docker" inputmode="decimal" />
                            <x-ui.campo label="Porta HTTP" wire:model="form.porta_http_docker" inputmode="numeric" />
                            <x-ui.campo label="Porta MySQL" wire:model="form.porta_mysql_docker" inputmode="numeric" />
                            <x-ui.campo label="Porta SSH" wire:model="form.porta_ssh_docker" inputmode="numeric" />
                            <x-ui.campo label="Porta SSH do contêiner" wire:model="form.porta_ssh_container_docker" inputmode="numeric" />
                            <x-ui.campo label="Senha SSH" tipo="password" icone="key" wire:model="form.senha_ssh_docker" autocomplete="new-password"
                                :ajuda="$this->senhasCadastradas['senha_ssh_docker'] ? 'Senha cadastrada. Preencha para substituir.' : 'Nenhuma senha cadastrada. Preencha para cadastrar.'" />
                        </div>
                    </x-ui.card>
                @endif

                <x-ui.card titulo="Servidor do cliente" icone="storage">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-ui.campo label="IP externo do servidor" icone="lan" wire:model="form.ip_externo_servidor" inputmode="decimal" />
                        <x-ui.campo label="Servidor MySQL" icone="dns" wire:model="form.servidor_mysql" />
                        <x-ui.campo label="Porta HTTP" wire:model="form.porta_http" inputmode="numeric" />
                        <x-ui.campo label="Porta MySQL" wire:model="form.porta_mysql" inputmode="numeric" />
                        <x-ui.campo label="Porta SSH" wire:model="form.porta_ssh" inputmode="numeric" />
                        <x-ui.campo label="Banco MySQL" icone="database" wire:model="form.banco_mysql" />
                        <x-ui.campo label="Senha MySQL" tipo="password" icone="key" wire:model="form.senha_mysql" autocomplete="new-password"
                            :ajuda="$this->senhasCadastradas['senha_mysql'] ? 'Senha cadastrada. Preencha para substituir.' : 'Nenhuma senha cadastrada. Preencha para cadastrar.'" />
                        <x-ui.campo label="Senha SSH" tipo="password" icone="key" wire:model="form.senha_ssh" autocomplete="new-password"
                            :ajuda="$this->senhasCadastradas['senha_ssh'] ? 'Senha cadastrada. Preencha para substituir.' : 'Nenhuma senha cadastrada. Preencha para cadastrar.'" />
                    </div>
                </x-ui.card>

                @if ($admin)
                    <div class="flex flex-wrap items-center gap-2">
                        <x-ui.botao type="submit" icone="save" wire:loading.attr="disabled" wire:target="salvar, importar, confirmarSenha">Salvar configuração</x-ui.botao>
                        <x-ui.botao variante="contorno" icone="cloud_download" wire:click="importar"
                            wire:loading.attr="disabled" wire:target="salvar, importar, confirmarSenha"
                            wire:confirm="Importar do Help Desk substitui os dados técnicos deste cliente pelos do cadastro de lá, menos tipo de servidor, tipo de PDV e emissão de cupons. Continuar?">Importar do Help Desk</x-ui.botao>
                    </div>
                @else
                    <x-ui.aviso>Só os admins do walle podem salvar ou importar a configuração.</x-ui.aviso>
                @endif
            </form>
        @endif
    </section>
</div>

<x-ui.confirmar-senha :aberta="$pedindoSenha" />
