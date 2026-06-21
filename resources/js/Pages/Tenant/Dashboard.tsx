import { Head } from '@inertiajs/react';
import { FlashMessages } from '@/Components/FlashMessages';
import { TenantNav } from '@/Components/TenantNav';
import { AppLayout } from '@/Layouts/AppLayout';
import { useI18n } from '@/lib/i18n';
import type { MemberRole, TenantData } from '@/types';

type AuthUser = {
    id: number;
    name: string;
    email: string;
    role: MemberRole;
};

type DashboardProps = {
    tenant: TenantData;
    auth_user: AuthUser;
    member_count: number;
    seat_limit: number;
};

/**
 * Authenticated tenant dashboard. Rendered inside tenant-DB context after the
 * session guard authenticated against the TENANT users table. `seat_limit === 0`
 * means an unlimited plan; otherwise we show usage against the cap. No password
 * or token ever reaches these props (contract §9).
 */
export default function Dashboard({ tenant, auth_user, member_count, seat_limit }: DashboardProps) {
    const { t } = useI18n();

    const seatsValue =
        seat_limit === 0
            ? `${member_count} · ${t('members.seat.unlimited')}`
            : `${member_count} / ${seat_limit}`;

    return (
        <AppLayout>
            <Head title={t('dashboard.title')} />

            <TenantNav current="dashboard" />
            <FlashMessages />

            <section>
                <h1 className="text-2xl font-bold">
                    {t('dashboard.welcome')} {tenant.name}
                </h1>

                <dl className="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Stat label={t('dashboard.plan')} value={tenant.plan} />
                    <Stat label={t('dashboard.status')} value={tenant.status} />
                    <Stat label={t('dashboard.members')} value={`${member_count}`} />
                    <Stat label={t('dashboard.seats')} value={seatsValue} />
                </dl>

                <div className="mt-8 rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <h2 className="text-sm font-semibold text-slate-500 dark:text-slate-400">
                        {t('dashboard.you')}
                    </h2>
                    <p className="mt-2 text-lg font-semibold">{auth_user.name}</p>
                    <p className="text-sm text-slate-500 dark:text-slate-400">{auth_user.email}</p>
                    <span className="mt-2 inline-flex items-center rounded-full bg-brand-50 px-2.5 py-0.5 text-xs font-semibold text-brand-700 dark:bg-brand-950/50 dark:text-brand-300">
                        {t(`members.role.${auth_user.role}`)}
                    </span>
                </div>
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
