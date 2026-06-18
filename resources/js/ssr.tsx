import { createInertiaApp } from '@inertiajs/react';
import createServer from '@inertiajs/react/server';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import ReactDOMServer from 'react-dom/server';
import { I18nProvider } from '@/lib/i18n';
import { ThemeProvider } from '@/lib/theme';
import type { ReactElement } from 'react';

const appName = import.meta.env.VITE_APP_NAME ?? 'CorporateNexus';

createServer((page) =>
    createInertiaApp({
        page,
        render: ReactDOMServer.renderToString,
        title: (title) => (title ? `${title} · ${appName}` : appName),
        resolve: (name) =>
            resolvePageComponent(
                `./Pages/${name}.tsx`,
                import.meta.glob<{ default: unknown }>('./Pages/**/*.tsx'),
            ),
        setup: ({ App, props }) =>
            (
                <ThemeProvider>
                    <I18nProvider locale={props.initialPage.props.locale as string}>
                        <App {...props} />
                    </I18nProvider>
                </ThemeProvider>
            ) as ReactElement,
    }),
);
