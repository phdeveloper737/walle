{{--
    Janela "confirme sua senha do Help Desk" das ações de admin (trait App\Livewire\Concerns\ConfirmaSenha).
    Ex.: <x-ui.confirmar-senha :aberta="$pedindoSenha" />
    O texto pode ser trocado pela tela quando a ação é mais específica:
    <x-ui.confirmar-senha :aberta="$pedindoSenha" texto="Esta ação faz uma consulta a Sefaz com o certificado de verdade." />
--}}
@props([
    'aberta' => false,
    'texto' => 'Esta ação mexe no servidor do cliente ou mostra um segredo.',
])

@if ($aberta)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-surface-container-lowest/80 p-4" role="dialog" aria-modal="true" aria-labelledby="confirmar-senha-titulo"
        x-data x-on:keydown.escape.window="$wire.cancelarSenha()">
        <form wire:submit="confirmarSenha" class="w-full max-w-sm rounded-xl bg-surface-container-high p-6 shadow-xl shadow-black/50 [--fundo-campo:var(--color-surface-container-high)]">
            <div class="flex items-center gap-3">
                <span class="flex size-10 items-center justify-center rounded-full bg-secondary-container text-on-secondary-container">
                    <x-ui.icone nome="admin_panel_settings" />
                </span>
                <h2 id="confirmar-senha-titulo" class="text-lg text-on-surface">Confirme que é você</h2>
            </div>
            <p class="mt-3 text-sm text-on-surface-variant">
                {{ $texto }} Digite a sua senha do Help Desk
                (vale por {{ config('walle.confirmar_senha_minutos') }} minutos). Fica registrado quem fez.
            </p>

            <div class="mt-4">
                <x-ui.campo label="Sua senha" tipo="password" icone="lock" wire:model="senhaConfirmacao" autocomplete="current-password" autofocus />
            </div>

            <div class="mt-5 flex justify-end gap-2">
                <x-ui.botao variante="texto" wire:click="cancelarSenha">Cancelar</x-ui.botao>
                <x-ui.botao type="submit" icone="check" wire:loading.attr="disabled" wire:target="confirmarSenha">Confirmar</x-ui.botao>
            </div>
        </form>
    </div>
@endif
