@use('App\Sactus\ArquivoCfg')
@use('App\Sactus\PainelSactus')
@use('App\Sactus\RotinaAcl')
@use('App\Sactus\RotinaErp')
@use('App\Models\AcaoSactus')
@use('App\Support\Numero')

<div class="flex flex-col gap-3 lg:flex-row lg:items-start">
    @php $admin = (bool) auth()->user()?->can('admin'); $ligado = (bool) config('walle.sactus.ativo'); @endphp

    {{-- Lista de clientes --}}
    <section class="flex w-full flex-col gap-2 lg:sticky lg:top-17 lg:w-[360px] lg:shrink-0 2xl:w-[400px]" aria-label="Clientes do Sactus">
        <x-ui.busca placeholder="Buscar cliente, CNPJ ou #id" wire:model.live.debounce.250ms="busca" />

        <div class="flex flex-wrap gap-1.5" role="group" aria-label="Filtrar">
            @foreach (PainelSactus::FILTROS as $chave => $rotulo)
                @if ($chave === 'todos' || $this->kpis[$chave] > 0)
                    <x-ui.chip :ativo="$filtro === $chave" :contagem="$this->kpis[$chave]" wire:click="$set('filtro', '{{ $chave }}')" wire:key="filtro-{{ $chave }}">
                        {{ $rotulo }}
                    </x-ui.chip>
                @endif
            @endforeach
        </div>

        <p class="px-1 text-xs text-on-surface-variant">{{ count($this->linhas) }} {{ count($this->linhas) === 1 ? 'cliente' : 'clientes' }}</p>

        <div class="flex flex-col gap-1.5 transition-opacity lg:max-h-[calc(100vh-15rem)] lg:overflow-y-auto lg:pr-1"
            wire:loading.class="opacity-60" wire:target="busca, filtro">
            @forelse ($this->linhas as $l)
                @php $escolhido = $l['id'] === $clienteId; @endphp
                <button type="button" wire:key="sactus-{{ $l['id'] }}" wire:click="selecionar({{ $l['id'] }})"
                    x-on:click="if (window.innerWidth < 1024) setTimeout(() => document.getElementById('detalhe-sactus')?.scrollIntoView({ behavior: 'smooth' }), 200)"
                    @if ($escolhido) aria-current="true" @endif
                    @class([
                        'relative w-full shrink-0 overflow-hidden rounded-md px-3 py-2.5 text-left transition',
                        'before:absolute before:inset-0 before:bg-on-surface before:opacity-0 before:transition-opacity hover:before:opacity-8',
                        'bg-secondary-container text-on-secondary-container' => $escolhido,
                        'bg-surface-container-high' => ! $escolhido,
                    ])>
                    <span class="relative flex items-start justify-between gap-3">
                        <span class="font-medium break-words">{{ $l['nome'] }}</span>
                        @if (! $l['coletado'])
                            <x-ui.etiqueta cor="erro">Falhou</x-ui.etiqueta>
                        @elseif ($l['obsoleto'])
                            <x-ui.etiqueta cor="secundaria">Desatualizado</x-ui.etiqueta>
                        @endif
                    </span>
                    <span @class(['relative mt-1.5 flex flex-wrap items-center gap-1.5 text-xs', 'text-on-surface-variant' => ! $escolhido])>
                        <span>#{{ $l['id'] }}</span>
                        @if ($l['metodo'] !== '')
                            <x-ui.etiqueta>{{ $l['metodo'] }}</x-ui.etiqueta>
                        @endif
                        @if ($l['acl'] !== 'na')
                            <x-ui.etiqueta :cor="['ok' => 'sucesso', 'aviso' => 'secundaria', 'falha' => 'erro'][$l['acl']]">ACL</x-ui.etiqueta>
                        @endif
                        @if ($l['erp'] !== 'na')
                            <x-ui.etiqueta :cor="$l['erp'] === 'ok' ? 'sucesso' : 'erro'">ERP</x-ui.etiqueta>
                        @endif
                        @if ($l['data'])
                            <span>{{ $l['data']->format('d/m H:i') }}</span>
                        @endif
                    </span>
                </button>
            @empty
                <x-ui.vazio icone="search_off" titulo="Nenhum cliente">Tente outro termo ou outro filtro.</x-ui.vazio>
            @endforelse
        </div>
    </section>

    {{-- Cliente escolhido --}}
    <section id="detalhe-sactus" class="min-w-0 flex-1 scroll-mt-16 rounded-lg bg-surface-container-low p-3 sm:p-4" aria-label="Cliente escolhido">
        @if ($p = $this->selecionado)
            <header class="flex flex-col gap-2 border-b border-outline-variant pb-3 sm:flex-row sm:items-start">
                <div class="min-w-0">
                    <p class="text-sm text-secondary">Cliente #{{ $p->fk_c_cliente }}</p>
                    <h2 class="text-2xl text-on-surface">{{ $this->nomeSelecionado() }}</h2>
                    <div class="mt-1 flex flex-wrap items-center gap-2 text-sm text-on-surface-variant">
                        <x-ui.etiqueta :cor="$p->coletado() ? 'sucesso' : 'erro'">{{ $p->coletado() ? 'Coletado' : 'Coleta falhou' }}</x-ui.etiqueta>
                        @if ($p->metodo)
                            <x-ui.etiqueta>{{ $p->metodo }}</x-ui.etiqueta>
                        @endif
                        <span>{{ $p->data ? 'em '.$p->data->format('d/m/Y H:i') : 'nunca coletado' }}</span>
                    </div>
                </div>
                @if ($admin && $ligado)
                    <x-ui.botao variante="tonal" icone="play_circle" class="sm:ml-auto" wire:click="aplicarAgora"
                        wire:confirm="Processar este cliente agora? Aplica os pedidos pendentes, coleta o sactus.cfg e roda ACL e ERP (se marcados no Help Desk).">
                        Aplicar agora
                    </x-ui.botao>
                @endif
            </header>

            @if (! $p->coletado() && $p->erro)
                <x-ui.aviso tipo="erro" class="mt-3">{{ $p->erro }}</x-ui.aviso>
            @endif

            @if ($aviso)
                <x-ui.aviso :tipo="$avisoErro ? 'erro' : 'sucesso'" class="mt-3" wire:key="aviso-{{ md5($aviso) }}">{{ $aviso }}</x-ui.aviso>
            @endif

            <x-ui.abas class="mt-2" :abas="array_map(fn ($r) => ['rotulo' => $r], \App\Livewire\Sactus\Clientes::DETALHES)" :atual="$detalhe" propriedade="detalhe" />

            <div class="mt-3">
                @switch($detalhe)
                    {{-- Arquivo bruto, com os segredos mascarados no servidor --}}
                    @case('arquivo')
                        @if (trim((string) $p->conteudo) === '')
                            <x-ui.vazio icone="description" titulo="Sem arquivo">A última coleta não trouxe o {{ config('walle.sactus.cfg_caminho') }}.</x-ui.vazio>
                        @else
                            <div class="mb-2 flex flex-wrap items-center gap-2">
                                <p class="text-xs text-on-surface-variant">
                                    {{ config('walle.sactus.cfg_caminho') }} · {{ Numero::inteiro(strlen($p->conteudo)) }} bytes
                                    · {{ $arquivoCompleto ? 'com os segredos à mostra' : 'senhas e tokens mascarados' }}
                                </p>
                                @if ($admin && ! $arquivoCompleto)
                                    <x-ui.botao variante="texto" icone="visibility" class="ml-auto" wire:click="revelarArquivo">Mostrar segredos</x-ui.botao>
                                @endif
                            </div>
                            <pre class="max-h-[32rem] overflow-auto rounded-md bg-surface-container-lowest p-3 font-mono text-xs leading-relaxed text-on-surface">{{ $arquivoCompleto ? $p->conteudo : ArquivoCfg::mascarar($p->conteudo) }}</pre>
                        @endif
                        @break

                    {{-- Conexão SSH usada (a senha nunca vem para cá) --}}
                    @case('conexao')
                        <dl class="grid grid-cols-[auto_minmax(0,1fr)] gap-x-6 gap-y-1.5 text-sm">
                            <dt class="text-on-surface-variant">Método</dt><dd class="text-on-surface">{{ $p->metodo ?: '—' }}</dd>
                            <dt class="text-on-surface-variant">Servidor</dt><dd class="font-mono text-on-surface">{{ $p->host ? $p->host.':'.$p->porta : '—' }}</dd>
                            <dt class="text-on-surface-variant">Usuário</dt><dd class="font-mono text-on-surface">{{ $p->usuario ?: '—' }}</dd>
                            <dt class="text-on-surface-variant">Tentativas</dt><dd class="text-on-surface">{{ $p->tentativas }}</dd>
                            <dt class="text-on-surface-variant">Duração</dt><dd class="text-on-surface">{{ Numero::inteiro($p->duracao_ms) }} ms</dd>
                            <dt class="text-on-surface-variant">Coleta</dt><dd class="text-on-surface">{{ $p->data?->format('d/m/Y H:i:s') ?? '—' }}</dd>
                            <dt class="text-on-surface-variant">Erro</dt><dd class="break-words text-on-surface">{{ $p->erro ?: '—' }}</dd>
                        </dl>
                        @break

                    {{-- ACL --}}
                    @case('acl')
                        @php $estadoAcl = PainelSactus::estadoAcl($p); @endphp
                        @if ($estadoAcl === 'na')
                            <x-ui.vazio icone="lock_open" titulo="ACL não avaliada">
                                O cliente não está com service_acl = 'S' no Help Desk. Marque lá para o robô aplicar as regras no próximo ciclo.
                            </x-ui.vazio>
                        @else
                            <p class="flex flex-wrap items-center gap-2 text-sm">
                                <x-ui.etiqueta :cor="['ok' => 'sucesso', 'aviso' => 'secundaria', 'falha' => 'erro'][$estadoAcl]">
                                    {{ ['ok' => 'Aplicada', 'aviso' => 'Aplicada sem efeito', 'falha' => 'Falhou'][$estadoAcl] }}
                                </x-ui.etiqueta>
                                <span class="text-on-surface">{{ RotinaAcl::ROTULOS[$p->acl_acao] ?? $p->acl_acao }}</span>
                                <span class="text-on-surface-variant">{{ $p->acl_data?->format('d/m/Y H:i') }}</span>
                            </p>
                            <div class="mt-3 overflow-x-auto">
                                <table class="w-full min-w-[560px] text-sm">
                                    <thead>
                                        <tr class="text-xs text-on-surface-variant">
                                            @foreach (['Arquivo', 'Usuário', 'Situação', 'Detalhe'] as $coluna)
                                                <th scope="col" class="border-b border-outline-variant px-2 py-1.5 text-left font-medium">{{ $coluna }}</th>
                                            @endforeach
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($p->itensAcl() as $item)
                                            <tr class="border-b border-outline-variant/50 last:border-0" wire:key="acl-{{ $loop->index }}">
                                                <td class="px-2 py-1.5 font-mono text-xs text-on-surface">{{ $item['arquivo'] ?? '' }}</td>
                                                <td class="px-2 py-1.5 text-on-surface-variant">{{ $item['usuario'] ?? '' }}</td>
                                                <td class="px-2 py-1.5">
                                                    <x-ui.etiqueta :cor="($item['status'] ?? 'N') !== 'S' ? 'erro' : (! empty($item['aviso']) ? 'secundaria' : 'sucesso')">
                                                        {{ RotinaAcl::ROTULOS[$item['acao'] ?? ''] ?? ($item['acao'] ?? '') }}
                                                    </x-ui.etiqueta>
                                                </td>
                                                <td class="px-2 py-1.5 text-xs text-on-surface-variant">{{ $item['erro'] ?? '' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                        @break

                    {{-- Atualização do ERP --}}
                    @case('erp')
                        @php $estadoErp = PainelSactus::estadoErp($p); @endphp
                        @if ($estadoErp === 'na')
                            <x-ui.vazio icone="system_update" titulo="ERP não atualizado pelo robô">
                                O cliente não está com atualizar_erp = 'S' no Help Desk.
                            </x-ui.vazio>
                        @else
                            <p class="flex flex-wrap items-center gap-2 text-sm">
                                <x-ui.etiqueta :cor="$estadoErp === 'ok' ? 'sucesso' : 'erro'">{{ $estadoErp === 'ok' ? 'Atualizado' : 'Falhou' }}</x-ui.etiqueta>
                                <span class="text-on-surface">{{ RotinaErp::ROTULOS[$p->erp_acao] ?? $p->erp_acao }}</span>
                                <span class="text-on-surface-variant">{{ $p->erp_data?->format('d/m/Y H:i') }}</span>
                            </p>
                            @if ($p->erp_erro)
                                <p class="mt-2 text-sm break-words text-error">{{ RotinaErp::mascarar($p->erp_erro) }}</p>
                            @endif
                            <ol class="mt-3 flex flex-col gap-2">
                                @foreach ($p->itensErp() as $item)
                                    <li class="rounded-md bg-surface-container p-3 text-sm" wire:key="erp-{{ $loop->index }}">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="text-on-surface-variant">{{ $item['ordem'] ?? '' }}.</span>
                                            <x-ui.etiqueta :cor="($item['status'] ?? 'N') === 'S' ? 'sucesso' : 'erro'">{{ RotinaErp::ROTULOS[$item['acao'] ?? ''] ?? ($item['acao'] ?? '') }}</x-ui.etiqueta>
                                            <span class="text-xs text-on-surface-variant">rc {{ $item['rc'] ?? '—' }} · {{ Numero::inteiro((int) ($item['duracao_ms'] ?? 0)) }} ms</span>
                                        </div>
                                        <p class="mt-1 font-mono text-xs break-all text-on-surface">{{ RotinaErp::mascarar((string) ($item['comando'] ?? '')) }}</p>
                                        @if (($item['erro'] ?? '') !== '')
                                            <p class="mt-1 text-xs text-error">{{ $item['erro'] }}</p>
                                        @endif
                                        @if (($item['saida'] ?? '') !== '')
                                            <details class="mt-1">
                                                <summary class="cursor-pointer text-xs text-primary">Saída</summary>
                                                <pre class="mt-1 max-h-60 overflow-auto rounded bg-surface-container-lowest p-2 font-mono text-[11px] text-on-surface-variant">{{ $item['saida'] }}</pre>
                                            </details>
                                        @endif
                                    </li>
                                @endforeach
                            </ol>
                        @endif
                        @break

                    {{-- Pedidos de edição e ações manuais --}}
                    @case('pedidos')
                        <div class="grid gap-4 xl:grid-cols-2">
                            <div>
                                <h3 class="font-medium text-on-surface">Pedir edição de flag</h3>
                                @if (! $admin)
                                    <p class="mt-1 text-sm text-on-surface-variant">Só os admins do walle (WALLE_ADMINS) podem pedir edições.</p>
                                @elseif (! $ligado)
                                    <p class="mt-1 text-sm text-on-surface-variant">Com o Sactus do walle desligado, peça pelo painel antigo.</p>
                                @else
                                    <form wire:submit="pedirEdicao" class="mt-3 flex flex-col gap-3 [--fundo-campo:var(--color-surface-container-low)]">
                                        <x-ui.segmentado wire:model.live="edTipo" :opcoes="['ADICIONAR' => 'Incluir ou alterar', 'REMOVER' => 'Remover']" />
                                        <x-ui.campo label="Flag" icone="flag" wire:model="edFlag" list="flags-cliente" autocomplete="off" spellcheck="false" class="font-mono" />
                                        <datalist id="flags-cliente">
                                            @foreach (array_keys($p->flags()) as $nome)
                                                <option value="{{ $nome }}"></option>
                                            @endforeach
                                        </datalist>
                                        @if ($edTipo === 'ADICIONAR')
                                            <x-ui.campo label="Valor" icone="edit" wire:model="edValor" autocomplete="off" spellcheck="false" class="font-mono" ajuda="Uma linha só, até 1.000 caracteres." />
                                        @endif
                                        <p class="text-xs text-on-surface-variant">O robô faz backup do arquivo, grava, relê e confere; se algo não bater, restaura o backup.</p>
                                        <div><x-ui.botao type="submit" icone="send">Registrar pedido</x-ui.botao></div>
                                    </form>
                                @endif

                                <h4 class="mt-5 text-sm font-medium text-on-surface">Últimos pedidos</h4>
                                @forelse ($this->pedidos as $ped)
                                    <div class="mt-2 rounded-md bg-surface-container p-3 text-sm" wire:key="pedido-{{ $ped->id }}">
                                        <p class="flex flex-wrap items-center gap-2">
                                            <x-ui.etiqueta :cor="['CONCLUIDO' => 'sucesso', 'ERRO' => 'erro', 'PROCESSANDO' => 'primaria'][$ped->status] ?? 'neutra'">{{ ucfirst(strtolower($ped->status)) }}</x-ui.etiqueta>
                                            <span class="font-mono text-on-surface">{{ $ped->tipo_edicao === 'REMOVER' ? '− ' : '+ ' }}{{ $ped->flag }}{{ $ped->tipo_edicao === 'ADICIONAR' ? '='.($sensivel($ped->flag) ? '••••••' : $ped->valor) : '' }}</span>
                                        </p>
                                        <p class="mt-1 text-xs text-on-surface-variant">nº {{ $ped->id }} · {{ $ped->solicitante ?: '—' }} · {{ $ped->criado_em->format('d/m/Y H:i') }}
                                            @if ($ped->processado_em) · processado {{ $ped->processado_em->format('d/m H:i') }} @endif</p>
                                        @if ($ped->mensagem)
                                            <p class="mt-1 text-xs break-words text-on-surface-variant">{{ $ped->mensagem }}</p>
                                        @endif
                                    </div>
                                @empty
                                    <p class="mt-1 text-sm text-on-surface-variant">Nenhum pedido para este cliente.</p>
                                @endforelse
                            </div>

                            <div>
                                <h3 class="font-medium text-on-surface">Ações no servidor</h3>
                                <p class="mt-1 text-sm text-on-surface-variant">Rodam uma vez, na fila, e ficam registradas com quem pediu.</p>
                                @if ($admin && $ligado)
                                    <div class="mt-3 flex flex-wrap gap-2">
                                        @foreach (AcaoSactus::ACOES as $acao => $rotulo)
                                            <x-ui.botao variante="contorno" icone="terminal" wire:click="pedirAcao('{{ $acao }}')" wire:key="acao-{{ $acao }}"
                                                wire:confirm="{{ $rotulo }} no servidor deste cliente? Pode levar vários minutos.">{{ $rotulo }}</x-ui.botao>
                                        @endforeach
                                    </div>
                                @endif

                                @forelse ($this->acoes as $acao)
                                    <div class="mt-2 rounded-md bg-surface-container p-3 text-sm" wire:key="acao-feita-{{ $acao->id }}">
                                        <p class="flex flex-wrap items-center gap-2">
                                            <x-ui.etiqueta :cor="['CONCLUIDO' => 'sucesso', 'ERRO' => 'erro', 'PROCESSANDO' => 'primaria'][$acao->status] ?? 'neutra'">{{ ucfirst(strtolower($acao->status)) }}</x-ui.etiqueta>
                                            <span class="text-on-surface">{{ $acao->rotulo() }}</span>
                                        </p>
                                        <p class="mt-1 text-xs text-on-surface-variant">nº {{ $acao->id }} · {{ $acao->solicitante }} · {{ $acao->criado_em->format('d/m/Y H:i') }}
                                            @if ($acao->alvo) · {{ $acao->alvo }} @endif @if ($acao->rc !== null) · rc {{ $acao->rc }} @endif</p>
                                        @if ($acao->erro)
                                            <p class="mt-1 text-xs break-words text-error">{{ $acao->erro }}</p>
                                        @endif
                                        @if ($acao->saida)
                                            <details class="mt-1">
                                                <summary class="cursor-pointer text-xs text-primary">Saída</summary>
                                                <pre class="mt-1 max-h-60 overflow-auto rounded bg-surface-container-lowest p-2 font-mono text-[11px] text-on-surface-variant">{{ $acao->saida }}</pre>
                                            </details>
                                        @endif
                                    </div>
                                @empty
                                    <p class="mt-2 text-sm text-on-surface-variant">Nenhuma ação neste cliente.</p>
                                @endforelse
                            </div>
                        </div>
                        @break

                    {{-- Flags --}}
                    @default
                        @php
                            $flags = $p->flags();
                            $termo = mb_strtolower(trim($buscaFlag));
                            $visiveis = array_filter($flags, fn ($v, $k) => $termo === '' || str_contains(mb_strtolower($k), $termo), ARRAY_FILTER_USE_BOTH);
                        @endphp
                        @if ($flags === [])
                            <x-ui.vazio icone="flag" titulo="Sem flags">A última coleta não trouxe o arquivo.</x-ui.vazio>
                        @else
                            <div class="mb-2 flex items-center gap-2">
                                <x-ui.busca class="max-w-xs flex-1" placeholder="Filtrar flags" wire:model.live.debounce.200ms="buscaFlag" />
                                <span class="text-xs text-on-surface-variant">{{ count($visiveis) }} de {{ count($flags) }}</span>
                            </div>
                            <div class="max-h-[32rem] overflow-auto">
                                <table class="w-full text-sm">
                                    <tbody>
                                        @foreach ($visiveis as $nome => $valor)
                                            <tr class="border-b border-outline-variant/50 last:border-0" wire:key="flag-{{ md5($nome) }}">
                                                <th scope="row" class="w-1/3 px-2 py-1.5 text-left font-mono text-xs font-normal break-all text-on-surface-variant">{{ $nome }}</th>
                                                <td class="px-2 py-1.5 font-mono text-xs break-all text-on-surface">
                                                    @if ($sensivel($nome) && ! array_key_exists($nome, $revelados))
                                                        <span class="text-on-surface-variant">••••••</span>
                                                        @if ($admin && $valor !== '')
                                                            <button type="button" wire:click="revelar(@js($nome))" class="ml-2 inline-flex items-center gap-1 font-sans text-primary hover:underline">
                                                                <x-ui.icone nome="visibility" class="text-[16px]" /> mostrar
                                                            </button>
                                                        @endif
                                                    @else
                                                        {{ $revelados[$nome] ?? ($valor === '' ? '(vazio)' : $valor) }}
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                @endswitch
            </div>
        @else
            <x-ui.vazio icone="dns" titulo="Escolha um cliente">Selecione um cliente na lista para ver o sactus.cfg dele.</x-ui.vazio>
        @endif
    </section>

    <x-ui.confirmar-senha :aberta="$pedindoSenha" />
</div>
