import { usePage } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import type { ReactNode } from 'react';
import { LocaleToggle } from '@/Components/LocaleToggle';
import { ThemeToggle } from '@/Components/ThemeToggle';
import { useI18n } from '@/lib/i18n';

/**
 * Application shell. Surfaces the current tenant context (org name + plan) in
 * the header per multitenancy §6, and moves focus to the main heading on
 * navigation for screen-reader users (inertia-react §9).
 */
export function AppLayout({ children }: { children: ReactNode }) {
    const { t } = useI18n();
    const { tenant } = usePage().props;
    const mainRef = useRef<HTMLElement>(null);

    useEffect(() => {
        mainRef.current?.focus();
    }, []);

    return (
        <div className="flex min-h-full flex-col">
            <a
                href="#main"
                className="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-md focus:bg-brand-600 focus:px-4 focus:py-2 focus:text-white"
            >
                {t('nav.dashboard')}
            </a>

            <header className="border-b border-slate-200 bg-white/80 backdrop-blur dark:border-slate-800 dark:bg-slate-900/80">
                <div className="mx-auto flex w-full max-w-6xl items-center justify-between gap-4 px-4 py-3">
                    <div className="flex items-center gap-3">
                        <span className="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-brand-600 text-sm font-bold text-white">
                            CN
                        </span>
                        <div className="leading-tight">
                            <p className="text-sm font-semibold">{tenant ? tenant.name : t('app.name')}</p>
                            {tenant ? (
                                <p className="text-xs text-slate-500 dark:text-slate-400">
                                    {t('dashboard.plan')}: {tenant.plan}
                                </p>
                            ) : null}
                        </div>
                    </div>

                    <nav aria-label="Utilities" className="flex items-center gap-2">
                        <LocaleToggle />
                        <ThemeToggle />
                    </nav>
                </div>
            </header>

            <main id="main" ref={mainRef} tabIndex={-1} className="mx-auto w-full max-w-6xl flex-1 px-4 py-10 focus:outline-none">
                {children}
            </main>

            <footer className="border-t border-slate-200 py-6 text-center text-xs text-slate-500 dark:border-slate-800 dark:text-slate-400">
                CorporateNexus · OMNIBUS
            </footer>
        </div>
    );
}
