import '../css/app.css';

import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import { route as routeFn } from 'ziggy-js';

declare global {
    const route: typeof routeFn;
}

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

// Styles injected at runtime by UI libraries (dialog scroll-lock, editor) must carry the page's CSP nonce.
const nonce = document.querySelector<HTMLMetaElement>('meta[name="csp-nonce"]')?.content;
if (nonce) (window as unknown as { __webpack_nonce__?: string }).__webpack_nonce__ = nonce;

createInertiaApp({
    // Public pages already carry the site name in their SEO title.
    title: (title) => (title.includes(appName) ? title : `${title} - ${appName}`),
    resolve: (name) => resolvePageComponent(`./pages/${name}.tsx`, import.meta.glob(['./pages/**/*.tsx', '!./pages/**/*.test.tsx'])),
    setup({ el, App, props }) {
        const root = createRoot(el);

        root.render(<App {...props} />);
    },
    // The bar's CSS lives in app.css: Inertia's own injected <style> would need an exception in the CSP.
    progress: {
        color: '#3b82f6',
        includeCSS: false,
    },
});
