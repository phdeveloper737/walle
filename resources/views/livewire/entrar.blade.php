<div class="w-full max-w-sm">
    <div class="mb-8 flex justify-center">
        <x-ui.logo grande />
    </div>

    <x-ui.card class="p-8">
        <h1 class="text-2xl text-on-surface">Entrar</h1>
        <p class="mt-1 mb-6 text-sm text-on-surface-variant">Use o mesmo usuário e senha do Help Desk.</p>

        <form wire:submit="entrar" class="flex flex-col gap-4">
            @error('login')
                <x-ui.aviso tipo="erro">{{ $message }}</x-ui.aviso>
            @enderror

            <x-ui.campo label="Usuário" icone="person" wire:model="usuario" autocomplete="username" autofocus />
            <x-ui.campo label="Senha" tipo="password" icone="lock" wire:model="senha" autocomplete="current-password" />

            <x-ui.botao type="submit" class="mt-2 w-full" wire:loading.attr="disabled" wire:target="entrar">
                <span wire:loading.remove wire:target="entrar">Entrar</span>
                <span wire:loading wire:target="entrar">Entrando…</span>
            </x-ui.botao>
        </form>
    </x-ui.card>
</div>
