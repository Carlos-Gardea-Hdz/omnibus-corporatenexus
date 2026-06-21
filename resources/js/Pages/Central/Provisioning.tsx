import { Head, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { AppLayout } from '@/Layouts/AppLayout';
import { useI18n } from '@/lib/i18n';
import type { TenantData } from '@/types';

type ProvisioningProps = {
    tenant: TenantData;
    tenant_url: string | null;
    is_active: boolean;
};

/**
 * Provisioning-status screen. The tenant DB is created by a QUEUED pipeline
 * (CreateDatabase → migrate → seed → MarkTenantActive), so the tenant stays
 * Pending until a worker drains it. We poll-reload only the status props every
 * 3s until `is_active`, then surface a link to the live workspace. The spinner
 * animation is suppressed under prefers-reduced-motion (a11y / WCAG 2.2).
 */
export default function Provisioning({ tenant, tenant_url, is_active }: ProvisioningProps) {
    const { t } = useI18n();
    const [reducedMotion, setReducedMotion] = useState(false);
    const intervalRef = useRef<ReturnType<typeof setInterval> | null>(null);

    useEffect(() => {
        const query = window.matchMedia('(prefers-reduced-motion: reduce)');
        setReducedMotion(query.matches);
        const onChange = (event: MediaQueryListEvent) => setReducedMotion(event.matches);
        query.addEventListener('change', onChange);
        return () => query.removeEventListener('change', onChange);
    }, []);

    useEffect(() => {
        if (is_active) {
            return;
        }
        intervalRef.current = setInterval(() => {
            router.reload({ only: ['tenant', 'tenant_url', 'is_active'] });
        }, 3000);
        return () => {
            if (intervalRef.current) {
                clearInterval(intervalRef.current);
            }
        };
    }, [is_active]);

    return (
        <AppLayout>
            <Head title={t('provisioning.title')} />

            <section className="mx-auto max-w-md text-center">
                <h1 className="text-2xl font-bold">{t('provisioning.title')}</h1>
                <p className="mt-2 text-lg font-medium">{tenant.name}</p>

                <div className="mt-8 flex flex-col items-center gap-4">
                    {is_active ? (
                        <span
                            className="inline-flex items-center gap-2 rounded-full bg-emerald-100 px-4 py-1.5 text-sm font-semibold text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300"
                            role="status"
                            aria-live="polite"
                        >
                            <span aria-hidden="true">✓</span>
                            {t('provisioning.active')}
                        </span>
                    ) : (
                        <>
                            <span
                                className="inline-flex items-center gap-2 rounded-full bg-amber-100 px-4 py-1.5 text-sm font-semibold text-amber-800 dark:bg-amber-950/60 dark:text-amber-300"
                                role="status"
                                aria-live="polite"
                            >
                                <span
                                    aria-hidden="true"
                                    className={`inline-block h-3 w-3 rounded-full border-2 border-amber-500 border-t-transparent ${
                                        reducedMotion ? '' : 'animate-spin'
                                    }`}
                                />
                                {t('provisioning.pending')}
                            </span>
                            <p className="text-sm text-slate-500 dark:text-slate-400">
                                {t('provisioning.wait')}
                            </p>
                        </>
                    )}

                    {is_active && tenant_url ? (
                        // A plain anchor (NOT Inertia <Link>): the tenant lives on a
                        // different subdomain/SPA, so this must be a full cross-origin
                        // document navigation, not a same-origin Inertia XHR visit.
                        <a
                            href={tenant_url}
                            rel="noopener noreferrer"
                            className="mt-2 inline-flex items-center justify-center rounded-lg bg-brand-600 px-6 py-3 font-medium text-white transition hover:bg-brand-700"
                        >
                            {t('provisioning.open')}
                        </a>
                    ) : null}
                </div>
            </section>
        </AppLayout>
    );
}
