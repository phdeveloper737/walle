@use('App\Sefaz\ChaveDeAcesso')
@use('Illuminate\Support\Carbon')

<div class="flex flex-col gap-3">
    <form wire:submit="verificar" class="flex flex-col gap-3 rounded-lg bg-surface-container-high p-4 sm:flex-row sm:items-start [--fundo-campo:var(--color-surface-container-high)]">
        <div class="min-w-0 flex-1">
            <x-ui.campo label="Chave de acesso" icone="key" wire:model="entrada" inputmode="numeric" autocomplete="off" spellcheck="false"
                class="font-mono tracking-wide" ajuda="Os 44 dígitos; pode colar com espaços ou com o “NFe” na frente." />
        </div>
        <x-ui.botao type="submit" icone="search" class="sm:mt-2" wire:loading.attr="disabled" wire:target="verificar">Verificar</x-ui.botao>
    </form>

    @if ($d = $this->diagnostico)
        @php $c = $d->chave; @endphp
        <div class="grid gap-3 lg:grid-cols-2">
            {{-- A chave, parte por parte --}}
            <section class="rounded-lg bg-surface-container-low p-4" aria-label="A chave">
                <h3 class="font-medium text-on-surface">A chave</h3>
                <p class="mt-1 font-mono text-xs break-all text-on-surface-variant">{{ $c->chave }}</p>

                @if ($d->problema)
                    <x-ui.aviso tipo="erro" class="mt-3">{{ $d->problema }}.</x-ui.aviso>
                @endif

                @if (strlen($c->chave) === 44)
                    <dl class="mt-3 grid grid-cols-[auto_minmax(0,1fr)] gap-x-4 gap-y-1.5 text-sm">
                        <dt class="text-on-surface-variant">UF</dt>
                        <dd class="text-on-surface">{{ ChaveDeAcesso::siglaUf($c->uf()) }} ({{ $c->uf() }})</dd>
                        <dt class="text-on-surface-variant">Emissão</dt>
                        <dd class="text-on-surface">{{ $c->referencia() ? Carbon::parse($c->referencia())->format('m/Y') : 'mês inválido' }}</dd>
                        <dt class="text-on-surface-variant">CNPJ</dt>
                        <dd class="text-on-surface">{{ $c->cnpjFormatado() }}</dd>
                        <dt class="text-on-surface-variant">Modelo</dt>
                        <dd class="text-on-surface">{{ ChaveDeAcesso::rotuloModelo($c->modelo()) }}</dd>
                        <dt class="text-on-surface-variant">Série · número</dt>
                        <dd class="text-on-surface">{{ (int) $c->serie() }} · {{ (int) $c->numero() }}</dd>
                        <dt class="text-on-surface-variant">Tipo de emissão</dt>
                        <dd class="text-on-surface">{{ ChaveDeAcesso::rotuloTipoEmissao($c->tipoEmissao()) }} ({{ $c->tipoEmissao() }})</dd>
                        <dt class="text-on-surface-variant">Dígito verificador</dt>
                        <dd @class(['flex items-center gap-1', 'text-success' => $c->digitoValido(), 'text-error' => ! $c->digitoValido()])>
                            <x-ui.icone :nome="$c->digitoValido() ? 'check_circle' : 'cancel'" class="text-[18px]" />
                            {{ $c->digitoValido() ? 'válido' : 'inválido' }}
                        </dd>
                    </dl>
                @endif
            </section>

            {{-- O robô e a base das autorizadas --}}
            <section class="rounded-lg bg-surface-container-low p-4" aria-label="Robô">
                <h3 class="font-medium text-on-surface">No robô</h3>

                @php
                    [$rotulo, $cor] = match ($d->fila['situacao']) {
                        'ok' => ['Autorizada', 'sucesso'],
                        'espera' => ['Na fila', 'primaria'],
                        default => ['Fora da fila', 'neutra'],
                    };
                @endphp
                <p class="mt-2 flex items-start gap-2 text-sm text-on-surface">
                    <x-ui.etiqueta :cor="$cor">{{ $rotulo }}</x-ui.etiqueta>
                    <span class="pt-0.5">{{ $d->fila['texto'] }}</span>
                </p>

                @if ($d->auth)
                    <dl class="mt-3 grid grid-cols-[auto_minmax(0,1fr)] gap-x-4 gap-y-1.5 text-sm">
                        <dt class="text-on-surface-variant">Autorizada</dt>
                        <dd class="text-on-surface">{{ $d->auth['autorizada'] ? 'Sim' : 'Não' }}</dd>
                        <dt class="text-on-surface-variant">Referência</dt>
                        <dd class="text-on-surface">{{ $d->auth['referencia'] ? Carbon::parse($d->auth['referencia'])->format('d/m/Y') : '—' }}</dd>
                        <dt class="text-on-surface-variant">Última consulta</dt>
                        <dd class="text-on-surface">{{ $d->auth['ultima_consulta'] ? Carbon::parse($d->auth['ultima_consulta'])->format('d/m/Y H:i:s') : 'nunca' }}</dd>
                        <dt class="text-on-surface-variant">Cliente</dt>
                        <dd class="truncate text-on-surface">{{ $this->nomeCliente($d->auth['cliente']) }}</dd>
                    </dl>
                @else
                    <p class="mt-3 text-sm text-on-surface-variant">Ainda não está na base das autorizadas (history_contingencia_auth).</p>
                @endif
            </section>

            {{-- Consulta na hora --}}
            <section class="rounded-lg bg-surface-container-low p-4 lg:col-span-2" aria-label="Consulta na Sefaz">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                    <div class="min-w-0">
                        <h3 class="font-medium text-on-surface">Na Sefaz, agora</h3>
                        <p class="mt-0.5 text-xs text-on-surface-variant">
                            Usa o certificado de verdade (no máximo {{ \App\Livewire\Autorizadas\Chave::CONSULTAS_POR_MINUTO }} por minuto, somando todo mundo).
                            Se voltar autorizada, fica gravada na base.
                        </p>
                    </div>
                    @if ($admin)
                        <x-ui.botao variante="tonal" icone="cloud_sync" class="sm:ml-auto" wire:click="consultarSefaz" wire:loading.attr="disabled" wire:target="consultarSefaz"
                            :disabled="$d->problema !== null">
                            <span wire:loading.remove wire:target="consultarSefaz">Consultar na Sefaz</span>
                            <span wire:loading wire:target="consultarSefaz">Consultando…</span>
                        </x-ui.botao>
                    @else
                        <p class="text-sm text-on-surface-variant sm:ml-auto sm:max-w-72 sm:text-right">
                            Só os admins do walle consultam a Sefaz na hora, porque a consulta usa o certificado de verdade.
                        </p>
                    @endif
                </div>

                @if ($erro)
                    <x-ui.aviso tipo="erro" class="mt-3">{{ $erro }}</x-ui.aviso>
                @endif

                @if ($resultado)
                    <div class="mt-3 flex flex-col gap-2 rounded-md bg-surface-container p-3 text-sm">
                        <p class="flex flex-wrap items-center gap-2">
                            <x-ui.etiqueta :cor="$resultado['ok'] ? 'sucesso' : ($resultado['cStat'] === null ? 'erro' : 'secundaria')">{{ $resultado['situacao'] }}</x-ui.etiqueta>
                            @if ($resultado['cStat'])
                                <span class="text-on-surface-variant">cStat {{ $resultado['cStat'] }}</span>
                            @endif
                            <span class="text-on-surface">{{ $resultado['motivo'] }}</span>
                        </p>
                        @if ($resultado['protocolo'])
                            <p class="text-on-surface-variant">
                                Protocolo <span class="font-mono text-on-surface">{{ $resultado['protocolo'] }}</span>
                                @if ($resultado['recebida_em'])
                                    · recebida em {{ Carbon::parse($resultado['recebida_em'])->format('d/m/Y H:i:s') }}
                                @endif
                            </p>
                        @endif
                        @if ($resultado['gravou'])
                            <p class="flex items-center gap-1 text-success"><x-ui.icone nome="save" class="text-[18px]" /> Gravada como autorizada na base.</p>
                        @endif
                    </div>
                @endif
            </section>

            {{-- Histórico de contingências --}}
            <section class="rounded-lg bg-surface-container-low p-4 lg:col-span-2" aria-label="Histórico de contingências">
                <h3 class="font-medium text-on-surface">No histórico de contingências</h3>

                @if ($d->historico === [])
                    <p class="mt-2 text-sm text-on-surface-variant">Nenhum registro no history_contingencia (é de lá que o robô semeia as chaves).</p>
                @else
                    <div class="mt-2 overflow-x-auto">
                        <table class="w-full min-w-[640px] text-sm">
                            <thead>
                                <tr class="text-xs text-on-surface-variant">
                                    @foreach (['Cliente', 'Série', 'Número', 'Data fiscal', 'Software', 'Motivo'] as $coluna)
                                        <th scope="col" class="border-b border-outline-variant px-2 py-1.5 text-left font-medium">{{ $coluna }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($d->historico as $h)
                                    <tr class="border-b border-outline-variant/50 last:border-0" wire:key="hist-{{ $loop->index }}">
                                        <td class="max-w-56 truncate px-2 py-1.5 text-on-surface">{{ $this->nomeCliente($h['cliente']) }}</td>
                                        <td class="px-2 py-1.5 text-on-surface-variant">{{ $h['serie'] ?: '—' }}</td>
                                        <td class="px-2 py-1.5 text-on-surface-variant">{{ $h['numero'] ?: '—' }}</td>
                                        <td class="px-2 py-1.5 whitespace-nowrap text-on-surface-variant">{{ $h['data_fiscal'] ? Carbon::parse($h['data_fiscal'])->format('d/m/Y H:i') : '—' }}</td>
                                        <td class="px-2 py-1.5 text-on-surface-variant">{{ $h['software'] ?: '—' }}</td>
                                        <td class="px-2 py-1.5 text-on-surface-variant">{{ $h['motivo'] ?: '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        </div>
    @else
        <x-ui.vazio icone="key" titulo="Informe uma chave">
            Mostra o que o walle sabe da chave, se o robô vai consultá-la e permite consultar na Sefaz na hora.
        </x-ui.vazio>
    @endif

    <x-ui.confirmar-senha :aberta="$pedindoSenha" texto="Esta ação faz uma consulta a Sefaz com o certificado de verdade." />
</div>
