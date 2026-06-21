import { Head, Link, router } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { ConsoleNav } from '@/Components/Platform/ConsoleNav';
import { StatusBadge } from '@/Components/Platform/StatusBadge';
import { FlashMessages } from '@/Components/FlashMessages';
import { AppLayout } from '@/Layouts/AppLayout';
import { useI18n } from '@/lib/i18n';
import type { TenantPlan, TenantStatus } from '@/types';

type Option = {
    value: string;
    label: string;
};

type TenantRow = {
    id: string;
    name: string;
    subdomain: string;
    status: TenantStatus;
    status_label: string;
    status_color: string;
    plan: TenantPlan;
    plan_label: string;
    owner_email: string;
    created_at: string;
};

type DashboardProps = {
    admin: { id: string; name: string; email: string };
    tenants: {
        data: TenantRow[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    filters: { status: string | null; plan: string | null };
    status_options: Option[];
    plan_options: Option[];
    counts: { total: number; by_status: Record<string, number> };
};

/**
 * Platform operator dashboard: the full tenant registry (central connection
 * only — never per-tenant models). Status/plan filters and the page link are
 * server-authoritative GET visits that re-query the central registry; the
 * `counts.by_status` summary is intentionally UNFILTERED (a program-wide
 * health snapshot). `owner_email` reaches a prop only because this is an
 * `auth:admin` console page — it is never exposed on any public/tenant prop.
 */
export default function Dashboard({
    admin,
    tenants,
    filters,
    status_options,
    plan_options,
    counts,
}: DashboardProps) {
    const { t } = useI18n();

    return (
        <AppLayout>
            <Head title={t('platform.dashboard.title')} />

            <ConsoleNav admin={admin} />
            <FlashMessages />

            <section>
                <header className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-bold">{t('platform.dashboard.title')}</h1>
                        <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
                            {t('platform.dashboard.subtitle')}
                        </p>
                    </div>
                    <p className="text-sm font-medium">
                        {t('platform.dashboard.total')}: {counts.total}
                    </p>
                </header>

                <StatusCounts statusOptions={status_options} byStatus={counts.by_status} />

                <Filters
                    filters={filters}
                    statusOptions={status_options}
                    planOptions={plan_options}
                />

                <TenantTable rows={tenants.data} />

                <Pagination
                    currentPage={tenants.current_page}
                    lastPage={tenants.last_page}
                    filters={filters}
                />
            </section>
        </AppLayout>
    );
}

/**
 * Unfiltered status tally — one chip per known status with its tenant count
 * (zero when absent). Reads counts by the raw enum value but renders the
 * server-provided option label, so copy stays enum-sourced.
 */
function StatusCounts({
    statusOptions,
    byStatus,
}: {
    statusOptions: Option[];
    byStatus: Record<string, number>;
}) {
    return (
        <ul className="mt-6 flex flex-wrap gap-2">
            {statusOptions.map((option) => (
                <li
                    key={option.value}
                    className="inline-flex items-center gap-2 rounded-full border border-slate-200 px-3 py-1 text-xs font-medium dark:border-slate-800"
                >
                    <span className="text-slate-600 dark:text-slate-300">{option.label}</span>
                    <span className="rounded-full bg-slate-100 px-2 py-0.5 font-semibold text-slate-700 dark:bg-slate-800 dark:text-slate-200">
                        {byStatus[option.value] ?? 0}
                    </span>
                </li>
            ))}
        </ul>
    );
}

function Filters({
    filters,
    statusOptions,
    planOptions,
}: {
    filters: { status: string | null; plan: string | null };
    statusOptions: Option[];
    planOptions: Option[];
}) {
    const { t } = useI18n();
    const [status, setStatus] = useState(filters.status ?? '');
    const [plan, setPlan] = useState(filters.plan ?? '');

    const apply = (event: FormEvent) => {
        event.preventDefault();
        const query: Record<string, string> = {};
        if (status) {
            query.status = status;
        }
        if (plan) {
            query.plan = plan;
        }
        router.get('/admin', query, { preserveState: true, preserveScroll: true });
    };

    const clear = () => {
        setStatus('');
        setPlan('');
        router.get('/admin', {}, { preserveScroll: true });
    };

    return (
        <form
            onSubmit={apply}
            className="mt-6 flex flex-wrap items-end gap-4 rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900"
        >
            <div>
                <label htmlFor="filter-status" className="block text-sm font-medium">
                    {t('platform.dashboard.filters.status')}
                </label>
                <select
                    id="filter-status"
                    value={status}
                    onChange={(event) => setStatus(event.target.value)}
                    className="mt-1 block w-44 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-500 dark:border-slate-700 dark:bg-slate-900"
                >
                    <option value="">{t('platform.dashboard.filters.all')}</option>
                    {statusOptions.map((option) => (
                        <option key={option.value} value={option.value}>
                            {option.label}
                        </option>
                    ))}
                </select>
            </div>

            <div>
                <label htmlFor="filter-plan" className="block text-sm font-medium">
                    {t('platform.dashboard.filters.plan')}
                </label>
                <select
                    id="filter-plan"
                    value={plan}
                    onChange={(event) => setPlan(event.target.value)}
                    className="mt-1 block w-44 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-500 dark:border-slate-700 dark:bg-slate-900"
                >
                    <option value="">{t('platform.dashboard.filters.all')}</option>
                    {planOptions.map((option) => (
                        <option key={option.value} value={option.value}>
                            {option.label}
                        </option>
                    ))}
                </select>
            </div>

            <div className="flex items-center gap-2">
                <button
                    type="submit"
                    className="inline-flex items-center justify-center rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-brand-700"
                >
                    {t('platform.dashboard.filters.apply')}
                </button>
                {filters.status || filters.plan ? (
                    <button
                        type="button"
                        onClick={clear}
                        className="inline-flex items-center justify-center rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-100 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                    >
                        {t('platform.dashboard.filters.clear')}
                    </button>
                ) : null}
            </div>
        </form>
    );
}

function TenantTable({ rows }: { rows: TenantRow[] }) {
    const { t } = useI18n();

    return (
        <div className="mt-6 overflow-x-auto rounded-xl border border-slate-200 dark:border-slate-800">
            <table className="w-full text-left text-sm">
                <thead className="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-slate-900/60 dark:text-slate-400">
                    <tr>
                        <th scope="col" className="px-4 py-3 font-medium">
                            {t('platform.dashboard.table.name')}
                        </th>
                        <th scope="col" className="px-4 py-3 font-medium">
                            {t('platform.dashboard.table.subdomain')}
                        </th>
                        <th scope="col" className="px-4 py-3 font-medium">
                            {t('platform.dashboard.table.status')}
                        </th>
                        <th scope="col" className="px-4 py-3 font-medium">
                            {t('platform.dashboard.table.plan')}
                        </th>
                        <th scope="col" className="px-4 py-3 font-medium">
                            {t('platform.dashboard.table.owner')}
                        </th>
                        <th scope="col" className="px-4 py-3 font-medium">
                            {t('platform.dashboard.table.created')}
                        </th>
                        <th scope="col" className="px-4 py-3 text-right font-medium">
                            <span className="sr-only">{t('platform.dashboard.view')}</span>
                        </th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-slate-200 bg-white dark:divide-slate-800 dark:bg-slate-950">
                    {rows.length === 0 ? (
                        <tr>
                            <td
                                colSpan={7}
                                className="px-4 py-8 text-center text-sm text-slate-500 dark:text-slate-400"
                            >
                                {t('platform.dashboard.empty')}
                            </td>
                        </tr>
                    ) : (
                        rows.map((row) => (
                            <tr key={row.id}>
                                <td className="px-4 py-3 font-medium">{row.name}</td>
                                <td className="px-4 py-3 font-mono text-xs text-slate-600 dark:text-slate-400">
                                    {row.subdomain}
                                </td>
                                <td className="px-4 py-3">
                                    <StatusBadge
                                        status={row.status}
                                        label={row.status_label}
                                        color={row.status_color}
                                    />
                                </td>
                                <td className="px-4 py-3">
                                    <span className="inline-flex items-center rounded-full bg-brand-50 px-2.5 py-0.5 text-xs font-semibold text-brand-700 dark:bg-brand-950/50 dark:text-brand-300">
                                        {row.plan_label}
                                    </span>
                                </td>
                                <td className="px-4 py-3 text-slate-600 dark:text-slate-400">
                                    {row.owner_email}
                                </td>
                                <td className="px-4 py-3 text-slate-600 dark:text-slate-400">
                                    {row.created_at}
                                </td>
                                <td className="px-4 py-3 text-right">
                                    <Link
                                        href={`/admin/tenants/${row.id}`}
                                        className="rounded-md border border-slate-300 px-2.5 py-1 text-sm font-medium text-slate-700 transition hover:bg-slate-100 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                                    >
                                        {t('platform.dashboard.view')}
                                    </Link>
                                </td>
                            </tr>
                        ))
                    )}
                </tbody>
            </table>
        </div>
    );
}

function Pagination({
    currentPage,
    lastPage,
    filters,
}: {
    currentPage: number;
    lastPage: number;
    filters: { status: string | null; plan: string | null };
}) {
    const { t } = useI18n();

    if (lastPage <= 1) {
        return null;
    }

    const go = (page: number) => {
        const query: Record<string, string | number> = { page };
        if (filters.status) {
            query.status = filters.status;
        }
        if (filters.plan) {
            query.plan = filters.plan;
        }
        router.get('/admin', query, { preserveScroll: true });
    };

    return (
        <nav
            aria-label={t('platform.pagination.page')}
            className="mt-6 flex items-center justify-between"
        >
            <button
                type="button"
                onClick={() => go(currentPage - 1)}
                disabled={currentPage <= 1}
                className="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 transition hover:bg-slate-100 disabled:opacity-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
            >
                {t('platform.pagination.previous')}
            </button>

            <span className="text-sm text-slate-500 dark:text-slate-400">
                {t('platform.pagination.page')} {currentPage} {t('platform.pagination.of')}{' '}
                {lastPage}
            </span>

            <button
                type="button"
                onClick={() => go(currentPage + 1)}
                disabled={currentPage >= lastPage}
                className="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 transition hover:bg-slate-100 disabled:opacity-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
            >
                {t('platform.pagination.next')}
            </button>
        </nav>
    );
}
