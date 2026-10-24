import react from '@vitejs/plugin-react';
import { readdirSync } from 'node:fs';
import laravel from 'laravel-vite-plugin';
import {
    defineConfig
} from 'vite';
import tailwindcss from "@tailwindcss/vite";

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
});