<!DOCTYPE html>
<html lang="pt-BR" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ isset($title) ? $title.' · walle' : 'walle' }}</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
{{-- menu: gaveta aberta no celular · recolhido: no computador, barra lateral só com ícones (lembrada no navegador) --}}
<body class="h-full" x-data="{ menu: false, recolhido: $persist(false).as('walle-menu-recolhido') }">
    {{-- Moldura: barra lateral --}}
    <aside
        class="fixed inset-y-0 left-0 z-30 flex w-64 -translate-x-full flex-col bg-surface-container-lowest px-3 py-3 transition-[width,transform] duration-200 lg:translate-x-0"
        x-bind:class="{ 'translate-x-0': menu, 'lg:w-20! lg:px-2!': recolhido }">
        <a href="{{ route('painel') }}" wire:navigate class="mb-4 flex h-12 items-center rounded-full px-3" x-bind:class="recolhido && 'lg:justify-center lg:px-0'">
            <span x-bind:class="recolhido && 'lg:hidden'"><x-ui.logo /></span>
            <span class="hidden" x-bind:class="recolhido && 'lg:inline-flex'"><x-ui.logo so-olhos /></span>
        </a>

        <nav class="flex flex-col gap-1" aria-label="Menu principal">
            <x-ui.menu-item rota="painel" icone="space_dashboard">Painel</x-ui.menu-item>
            <x-ui.menu-item rota="contingencias" icone="receipt_long">Contingências</x-ui.menu-item>
            <x-ui.menu-item rota="autorizadas" icone="verified">Autorizadas</x-ui.menu-item>
            <x-ui.menu-item rota="sactus" icone="dns">Sactus</x-ui.menu-item>
            <x-ui.menu-item rota="saude" icone="monitor_heart">Saúde do sistema</x-ui.menu-item>
            <x-ui.menu-item href="/horizon" icone="stacks" target="_blank">Filas (Horizon)</x-ui.menu-item>
        </nav>

        <p class="mt-auto px-3 text-xs text-on-surface-variant" x-bind:class="recolhido && 'lg:hidden'">walle · Laravel {{ app()->version() }}</p>
    </aside>

    <div class="fixed inset-0 z-20 bg-surface-container-lowest/70 lg:hidden" x-show="menu" x-cloak x-on:click="menu = false"></div>

    <div class="flex min-h-full flex-col transition-[padding] duration-200 lg:pl-64" x-bind:class="recolhido && 'lg:pl-20!'">
        {{-- Moldura: barra do topo --}}
        <header class="sticky top-0 z-10 flex h-14 items-center gap-1 bg-surface-container-lowest px-2 sm:px-3">
            <x-ui.botao-icone icone="menu" rotulo="Mostrar ou recolher o menu"
                x-on:click="window.innerWidth >= 1024 ? (recolhido = ! recolhido) : (menu = true)" />

            <h1 class="truncate text-lg text-on-surface">{{ $title ?? '' }}</h1>

            <div class="ml-auto flex items-center gap-2">
                <span class="hidden items-center gap-2 sm:flex">
                    <span class="flex size-8 items-center justify-center rounded-full bg-secondary text-sm font-medium text-on-secondary" aria-hidden="true">
                        {{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}
                    </span>
                    <span class="text-sm text-on-surface-variant">{{ auth()->user()->name }}</span>
                </span>

                <form method="POST" action="{{ route('sair') }}">
                    @csrf
                    <x-ui.botao type="submit" variante="texto" icone="logout">Sair</x-ui.botao>
                </form>
            </div>
        </header>

        <main class="flex-1 p-3 sm:p-4 xl:p-5">
            {{ $slot }}
        </main>
    </div>
</body>
</html>
