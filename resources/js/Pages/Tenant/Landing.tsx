import { Head } from '@inertiajs/react';
import { AppLayout } from '@/Layouts/AppLayout';
import { useI18n } from '@/lib/i18n';
import type { TenantData } from '@/types';

type LandingProps = {
    tenant: TenantData;
    notes_count: number;
};

/**
 * Minimal tenant landing. Rendered AFTER tenancy identification on the tenant
 * domain, so `notes_count` is a live COUNT from the TENANT notes table — the
 * visible proof that we are inside tenant-DB context (multitenancy isolation).
 */
export default function Landing({ tenant, notes_count }: LandingProps) {
    const { t } = useI18n();

    return (
        <AppLayout>
            <Head title={t('landing.title')} />

            <section>
                <h1 className="text-2xl font-bold">
                    {t('landing.welcome')} {tenant.name}
                </h1>

                <dl className="mt-6 grid gap-4 sm:grid-cols-2">
                    <Stat label={t('dashboard.plan')} value={tenant.plan} />
                    <Stat label={t('landing.notes_count')} value={`${notes_count}`} />
                </dl>
            </section>
        </AppLayout>
    );
}

function Stat({ label, value }: { label: string; value: string }) {
    return (
        <div className="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
            <dt className="text-sm text-slate-500 dark:text-slate-400">{label}</dt>
            <dd className="mt-1 text-lg font-semibold capitalize">{value}</dd>
        </div>
    );
}
