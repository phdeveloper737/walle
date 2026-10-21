<!DOCTYPE html>
<html lang="pt-BR" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ isset($title) ? $title.' · walle' : 'walle' }}</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full" x-data="{ menu: false }">
    {{-- Moldura: barra lateral (no celular abre por cima) --}}
    <aside
        class="fixed inset-y-0 left-0 z-30 flex w-72 -translate-x-full flex-col bg-surface-container-lowest px-3 py-4 transition-transform lg:translate-x-0"
        x-bind:class="menu && 'translate-x-0'">
        <a href="{{ route('painel') }}" wire:navigate class="mb-6 rounded-full px-4 py-2">
            <x-ui.logo />
        </a>

        <nav class="flex flex-col gap-1" aria-label="Menu principal">
            <x-ui.menu-item rota="painel" icone="space_dashboard">Painel</x-ui.menu-item>
            <x-ui.menu-item rota="saude" icone="monitor_heart">Saúde do sistema</x-ui.menu-item>
            <x-ui.menu-item href="/horizon" icone="stacks" target="_blank">Filas (Horizon)</x-ui.menu-item>
        </nav>

        <p class="mt-auto px-4 text-xs text-on-surface-variant">walle · Laravel {{ app()->version() }}</p>
    </aside>

    <div class="fixed inset-0 z-20 bg-surface-container-lowest/70 lg:hidden" x-show="menu" x-cloak x-on:click="menu = false"></div>

    <div class="flex min-h-full flex-col lg:pl-72">
        {{-- Moldura: barra do topo --}}
        <header class="sticky top-0 z-10 flex h-16 items-center gap-2 bg-surface-container-lowest px-4 lg:px-6">
            <button type="button" class="flex size-10 items-center justify-center rounded-full text-on-surface-variant hover:bg-on-surface/8 lg:hidden"
                x-on:click="menu = true" aria-label="Abrir menu">
                <x-ui.icone nome="menu" />
            </button>

            <h1 class="truncate text-xl text-on-surface">{{ $title ?? '' }}</h1>

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

        <main class="flex-1 p-4 sm:p-6 lg:p-8">
            {{ $slot }}
        </main>
    </div>
</body>
</html>
