import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    // Vite dentro do Docker (contêiner walle-vite) servindo o navegador do Windows
    server: {
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        hmr: { host: 'localhost' },
        watch: {
            // A pasta compartilhada do Windows não avisa mudanças ao Linux: conferir periodicamente
            usePolling: true,
            interval: 300,
            ignored: ['**/storage/**', '**/vendor/**', '**/node_modules/**'],
        },
    },
});
