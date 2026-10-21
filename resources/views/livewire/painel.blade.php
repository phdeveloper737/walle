<div class="flex flex-col gap-4">
    <div>
        <p class="text-sm text-secondary">{{ now()->translatedFormat('l, d \d\e F') }}</p>
        <h2 class="mt-1 text-3xl text-on-surface">{{ $saudacao }}, {{ $nome }}.</h2>
    </div>

    <div class="grid gap-3 md:grid-cols-2 2xl:grid-cols-3">
        <x-ui.card titulo="O walle está começando" icone="rocket_launch">
            <p class="text-sm text-on-surface-variant">
                Esta é a base do novo sistema: login, visual e infraestrutura. As telas do newsuporte
                chegam aqui uma a uma, começando pelas mais usadas.
            </p>
        </x-ui.card>

        <x-ui.card titulo="Atalhos" icone="bolt">
            <div class="flex flex-wrap gap-2">
                <x-ui.botao variante="tonal" icone="monitor_heart" :href="route('saude')" wire:navigate>Saúde do sistema</x-ui.botao>
                <x-ui.botao variante="contorno" icone="stacks" href="/horizon" target="_blank">Filas</x-ui.botao>
            </div>
        </x-ui.card>
    </div>
</div>
