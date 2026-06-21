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

type FeatureRow = {
    value: string;
    label: string;
    active: boolean;
};

type TenantDetail = {
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
    seat_limit: number;
    price_cents: number;
    features: FeatureRow[];
    over_seat_limit: boolean;
    seats_over: number;
};

type ShowProps = {
    admin: { id: string; name: string; email: string };
    tenant: TenantDetail;
    allowed_transitions: Option[];
    assignable_plans: Option[];
    can: {
        suspend: boolean;
        reactivate: boolean;
        change_plan: boolean;
        retry: boolean;
        archive: boolean;
    };
};

/**
 * Platform operator tenant detail + lifecycle actions (central connection
 * only). Every capability (`can.*`, `allowed_transitions`, `assignable_plans`)
 * is computed server-side from the `TenantStatus::canTransitionTo()` graph, so
 * the console only ever offers a legal action — an illegal transition is also
 * guarded again server-side (302 + error, never 500/422). Suspend/reactivate
 * are PATCH Inertia visits; the plan change posts the `ChangeTenantPlanData`
 * DTO. `owner_email` rides this `auth:admin` console prop only — never a
 * public/tenant prop — and no password/token reaches any prop.
 */
export default function Show({
    admin,
    tenant,
    assignable_plans,
    can,
}: ShowProps) {
    const { t } = useI18n();
    const priceLabel = `$${(tenant.price_cents / 100).toFixed(2)}`;
    const seatLabel =
        tenant.seat_limit === 0
            ? t('platform.tenant.seat_limit.unlimited')
            : String(tenant.seat_limit);

    const hasActions =
        can.suspend || can.reactivate || can.change_plan || can.retry || can.archive;

    return (
        <AppLayout>
            <Head title={`${tenant.name} · ${t('platform.tenant.detail_title')}`} />

            <ConsoleNav admin={admin} />
            <FlashMessages />

            <section>
                <Link
                    href="/admin"
                    className="text-sm font-medium text-brand-600 hover:underline dark:text-brand-400"
                >
                    ← {t('platform.tenant.back')}
                </Link>

                <header className="mt-3 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-bold">{tenant.name}</h1>
                        <p className="mt-1 font-mono text-sm text-slate-500 dark:text-slate-400">
                            {tenant.subdomain}
                        </p>
                    </div>
                    <StatusBadge
                        status={tenant.status}
                        label={tenant.status_label}
                        color={tenant.status_color}
                    />
                </header>

                <dl className="mt-8 grid gap-6 rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900 sm:grid-cols-2">
                    <Field label={t('platform.tenant.plan')} value={tenant.plan_label} />
                    <Field label={t('platform.tenant.owner_email')} value={tenant.owner_email} />
                    <Field label={t('platform.tenant.seat_limit')} value={seatLabel} />
                    <Field
                        label={t('platform.tenant.price')}
                        value={`${priceLabel} ${t('platform.tenant.price.per_month')}`}
                    />
                    <Field label={t('platform.tenant.created_at')} value={tenant.created_at} />
                </dl>

                {tenant.over_seat_limit ? (
                    <p
                        role="status"
                        className="mt-4 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-800 dark:border-amber-900 dark:bg-amber-950/50 dark:text-amber-300"
                    >
                        {t('platform.tenant.over_limit')} · {tenant.seats_over}{' '}
                        {t('platform.tenant.seats_over')}
                    </p>
                ) : null}

                <Features features={tenant.features} />

                {hasActions ? (
                    <Actions
                        tenantId={tenant.id}
                        currentPlan={tenant.plan}
                        can={can}
                        assignablePlans={assignable_plans}
                    />
                ) : (
                    <p className="mt-8 text-sm text-slate-500 dark:text-slate-400">
                        {t('platform.actions.none')}
                    </p>
                )}
            </section>
        </AppLayout>
    );
}

function Field({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <dt className="text-xs font-medium uppercase text-slate-500 dark:text-slate-400">
                {label}
            </dt>
            <dd className="mt-1 text-sm font-medium">{value}</dd>
        </div>
    );
}

function Features({ features }: { features: FeatureRow[] }) {
    const { t } = useI18n();

    if (features.length === 0) {
        return null;
    }

    return (
        <div className="mt-8">
            <h2 className="text-sm font-semibold">{t('platform.tenant.features')}</h2>
            <ul className="mt-3 flex flex-wrap gap-2">
                {features.map((feature) => (
                    <li
                        key={feature.value}
                        className={`inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-medium ${
                            feature.active
                                ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300'
                                : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400'
                        }`}
                    >
                        <span aria-hidden="true">{feature.active ? '✓' : '○'}</span>
                        {feature.label}
                        <span className="sr-only">
                            {feature.active
                                ? t('platform.tenant.feature.active')
                                : t('platform.tenant.feature.inactive')}
                        </span>
                    </li>
                ))}
            </ul>
        </div>
    );
}

function Actions({
    tenantId,
    currentPlan,
    can,
    assignablePlans,
}: {
    tenantId: string;
    currentPlan: TenantPlan;
    can: {
        suspend: boolean;
        reactivate: boolean;
        change_plan: boolean;
        retry: boolean;
        archive: boolean;
    };
    assignablePlans: Option[];
}) {
    const { t } = useI18n();
    const [busy, setBusy] = useState<
        'suspend' | 'reactivate' | 'retry' | 'archive' | null
    >(null);

    const patch = (action: 'suspend' | 'reactivate' | 'retry' | 'archive') => {
        router.patch(
            `/admin/tenants/${tenantId}/${action}`,
            {},
            {
                preserveScroll: true,
                onStart: () => setBusy(action),
                onFinish: () => setBusy(null),
            },
        );
    };

    const suspend = () => {
        if (!window.confirm(t('platform.actions.suspend.confirm'))) {
            return;
        }
        patch('suspend');
    };

    const reactivate = () => patch('reactivate');

    const retry = () => {
        if (!window.confirm(t('platform.actions.retry.confirm'))) {
            return;
        }
        patch('retry');
    };

    const archive = () => {
        if (!window.confirm(t('platform.actions.archive.confirm'))) {
            return;
        }
        patch('archive');
    };

    return (
        <div className="mt-8 rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
            <h2 className="text-sm font-semibold">{t('platform.actions.title')}</h2>

            <div className="mt-4 flex flex-wrap items-center gap-3">
                {can.suspend ? (
                    <button
                        type="button"
                        onClick={suspend}
                        disabled={busy !== null}
                        className="inline-flex items-center justify-center rounded-lg border border-rose-300 px-4 py-2 text-sm font-medium text-rose-700 transition hover:bg-rose-50 disabled:opacity-60 dark:border-rose-900 dark:text-rose-300 dark:hover:bg-rose-950/40"
                    >
                        {busy === 'suspend'
                            ? t('platform.actions.suspending')
                            : t('platform.actions.suspend')}
                    </button>
                ) : null}

                {can.reactivate ? (
                    <button
                        type="button"
                        onClick={reactivate}
                        disabled={busy !== null}
                        className="inline-flex items-center justify-center rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-brand-700 disabled:opacity-60"
                    >
                        {busy === 'reactivate'
                            ? t('platform.actions.reactivating')
                            : t('platform.actions.reactivate')}
                    </button>
                ) : null}

                {can.retry ? (
                    <button
                        type="button"
                        onClick={retry}
                        disabled={busy !== null}
                        className="inline-flex items-center justify-center rounded-lg border border-amber-300 px-4 py-2 text-sm font-medium text-amber-700 transition hover:bg-amber-50 disabled:opacity-60 dark:border-amber-900 dark:text-amber-300 dark:hover:bg-amber-950/40"
                    >
                        {busy === 'retry'
                            ? t('platform.actions.retrying')
                            : t('platform.actions.retry')}
                    </button>
                ) : null}

                {can.archive ? (
                    <button
                        type="button"
                        onClick={archive}
                        disabled={busy !== null}
                        className="inline-flex items-center justify-center rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-100 disabled:opacity-60 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                    >
                        {busy === 'archive'
                            ? t('platform.actions.archiving')
                            : t('platform.actions.archive')}
                    </button>
                ) : null}
            </div>

            {can.change_plan && assignablePlans.length > 0 ? (
                <ChangePlanForm
                    tenantId={tenantId}
                    currentPlan={currentPlan}
                    assignablePlans={assignablePlans}
                />
            ) : null}
        </div>
    );
}

function ChangePlanForm({
    tenantId,
    currentPlan,
    assignablePlans,
}: {
    tenantId: string;
    currentPlan: TenantPlan;
    assignablePlans: Option[];
}) {
    const { t } = useI18n();
    const defaultPlan =
        (assignablePlans.find((p) => p.value === currentPlan)?.value ??
            assignablePlans[0]?.value) as TenantPlan;
    const [plan, setPlan] = useState<TenantPlan>(defaultPlan);
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        router.patch(
            `/admin/tenants/${tenantId}/plan`,
            { plan },
            {
                preserveScroll: true,
                onStart: () => {
                    setProcessing(true);
                    setError(null);
                },
                onError: (errors) => setError(errors.plan ?? null),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <form onSubmit={submit} className="mt-6 flex flex-wrap items-end gap-3 border-t border-slate-200 pt-6 dark:border-slate-800">
            <div>
                <label htmlFor="change-plan" className="block text-sm font-medium">
                    {t('platform.actions.change_plan.label')}
                </label>
                <select
                    id="change-plan"
                    value={plan}
                    onChange={(event) => setPlan(event.target.value as TenantPlan)}
                    aria-invalid={error ? true : undefined}
                    aria-describedby={error ? 'change-plan-error' : undefined}
                    className="mt-1 block w-48 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-500 dark:border-slate-700 dark:bg-slate-900"
                >
                    {assignablePlans.map((option) => (
                        <option key={option.value} value={option.value}>
                            {option.label}
                        </option>
                    ))}
                </select>
                {error ? (
                    <p
                        id="change-plan-error"
                        role="alert"
                        className="mt-1 text-sm text-rose-600 dark:text-rose-400"
                    >
                        {error}
                    </p>
                ) : null}
            </div>

            <button
                type="submit"
                disabled={processing}
                className="inline-flex items-center justify-center rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-100 disabled:opacity-60 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
            >
                {processing
                    ? t('platform.actions.changing_plan')
                    : t('platform.actions.change_plan')}
            </button>
        </form>
    );
}
