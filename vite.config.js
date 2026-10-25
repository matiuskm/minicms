import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { readdirSync } from 'node:fs';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [
        laravel({
            // One CSS entry per self-hosted font, so a page only loads the families the theme uses.
            input: [
                'resources/css/app.css',
                'resources/js/app.tsx',
                ...readdirSync('resources/css/fonts')
                    .filter((f) => f.endsWith('.css') && f !== 'all.css')
                    .map((f) => `resources/css/fonts/${f}`),
            ],
            ssr: 'resources/js/ssr.jsx',
            refresh: true,
        }),
        react(),
        tailwindcss(),
    ],
    esbuild: {
        jsx: 'automatic',
    },
    // The release archive has no node_modules, so the server-side bundle must carry its own dependencies.
    ssr: {
        noExternal: true,
    },
});
