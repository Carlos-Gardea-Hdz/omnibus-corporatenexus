import '../css/app.css';

import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot, hydrateRoot } from 'react-dom/client';
import { I18nProvider } from '@/lib/i18n';
import { ThemeProvider } from '@/lib/theme';
import type { ReactElement } from 'react';

const appName = import.meta.env.VITE_APP_NAME ?? 'CorporateNexus';

void createInertiaApp({
    title: (title) => (title ? `${title} · ${appName}` : appName),
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.tsx`,
            import.meta.glob<{ default: unknown }>('./Pages/**/*.tsx'),
        ),
    setup({ el, App, props }) {
        const tree = (
            <ThemeProvider>
                <I18nProvider locale={props.initialPage.props.locale as string}>
                    <App {...props} />
                </I18nProvider>
            </ThemeProvider>
        ) as ReactElement;

        // Hydrate when SSR markup is present, otherwise mount fresh.
        if (el.hasChildNodes()) {
            hydrateRoot(el, tree);
        } else {
            createRoot(el).render(tree);
        }
    },
    progress: { color: '#7c3aed' },
});
