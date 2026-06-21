import { Head, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { AppLayout } from '@/Layouts/AppLayout';
import { useI18n } from '@/lib/i18n';
import type { TenantData } from '@/types';

type ProvisioningProps = {
    tenant: TenantData;
    tenant_url: string | null;
    is_active: boolean;
    owner_temp_password: string | null;
};

/**
 * Provisioning-status screen. The tenant DB is created by a QUEUED pipeline
 * (CreateDatabase → migrate → seed → MarkTenantActive), so the tenant stays
 * Pending until a worker drains it. We poll-reload only the status props every
 * 3s until `is_active`, then surface a link to the live workspace. The spinner
 * animation is suppressed under prefers-reduced-motion (a11y / WCAG 2.2).
 */
export default function Provisioning({
    tenant,
    tenant_url,
    is_active,
    owner_temp_password,
}: ProvisioningProps) {
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
            router.reload({ only: ['tenant', 'tenant_url', 'is_active', 'owner_temp_password'] });
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

                    {is_active && owner_temp_password ? (
                        <OwnerCredential password={owner_temp_password} />
                    ) : null}

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

/**
 * One-time reveal of the owner's temporary password — the single sanctioned
 * place a credential reaches a prop (email delivery is deferred). It is shown
 * once after provisioning completes; never persisted into any list prop.
 */
function OwnerCredential({ password }: { password: string }) {
    const { t } = useI18n();
    const [copied, setCopied] = useState(false);

    const copy = () => {
        void navigator.clipboard?.writeText(password).then(() => {
            setCopied(true);
            window.setTimeout(() => setCopied(false), 2000);
        });
    };

    return (
        <div className="mt-4 w-full rounded-xl border border-amber-300 bg-amber-50 p-4 text-left dark:border-amber-900 dark:bg-amber-950/40">
            <h2 className="text-sm font-semibold text-amber-900 dark:text-amber-200">
                {t('provisioning.owner_credential.title')}
            </h2>
            <p className="mt-1 text-xs text-amber-800 dark:text-amber-300">
                {t('provisioning.owner_credential.hint')}
            </p>
            <div className="mt-3 flex items-center gap-2">
                <code className="flex-1 rounded-md border border-amber-300 bg-white px-3 py-2 font-mono text-sm text-slate-900 dark:border-amber-900 dark:bg-slate-900 dark:text-slate-100">
                    {password}
                </code>
                <button
                    type="button"
                    onClick={copy}
                    className="rounded-md border border-amber-400 px-3 py-2 text-sm font-medium text-amber-900 transition hover:bg-amber-100 dark:border-amber-800 dark:text-amber-200 dark:hover:bg-amber-950/60"
                >
                    {copied ? t('members.temp_password.copied') : t('members.temp_password.copy')}
                </button>
            </div>
        </div>
    );
}
