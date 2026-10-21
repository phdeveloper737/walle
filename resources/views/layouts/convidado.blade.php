<!DOCTYPE html>
<html lang="pt-BR" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ isset($title) ? $title.' · walle' : 'walle' }}</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-full">
    <main class="flex min-h-screen items-center justify-center bg-[radial-gradient(ellipse_at_top,var(--color-surface-container-high),var(--color-surface)_55%,var(--color-surface-container-lowest))] p-4">
        {{ $slot }}
    </main>
</body>
</html>
