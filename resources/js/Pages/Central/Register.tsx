import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { AppLayout } from '@/Layouts/AppLayout';
import { useI18n } from '@/lib/i18n';
import type { CreateTenantData, PlanOptionData } from '@/types';

type RegisterProps = {
    plans: PlanOptionData[];
};

/**
 * Tenant self-registration. Validation is server-authoritative (the matching
 * rules live on CreateTenantData); the form shape is the generated TS type so
 * the client never re-declares it (inertia-react §2, §4). The plan picker is
 * driven by the server-provided PlanOptionData[] — prices arrive as integer
 * cents and are formatted client-side (never float, never recomputed).
 */
export default function Register({ plans }: RegisterProps) {
    const { t, locale } = useI18n();

    const { data, setData, post, processing, errors } = useForm<CreateTenantData>({
        name: '',
        subdomain: '',
        ownerEmail: '',
        plan: 'free',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post('/register');
    };

    const formatPrice = (priceCents: number): string => {
        if (priceCents === 0) {
            return t('register.plan.free');
        }
        return new Intl.NumberFormat(locale === 'es' ? 'es-MX' : 'en-US', {
            style: 'currency',
            currency: 'USD',
        }).format(priceCents / 100);
    };

    const formatSeats = (seatLimit: number): string => {
        if (seatLimit === 0) {
            return t('register.plan.unlimited');
        }
        return `${seatLimit} ${t('register.plan.seats')}`;
    };

    return (
        <AppLayout>
            <Head title={t('register.title')} />

            <section className="mx-auto max-w-md">
                <h1 className="text-2xl font-bold">{t('register.title')}</h1>

                <form onSubmit={submit} className="mt-6 space-y-5" noValidate>
                    <Field
                        id="name"
                        label={t('register.name')}
                        value={data.name}
                        error={errors.name}
                        onChange={(value) => setData('name', value)}
                        autoComplete="organization"
                    />
                    <Field
                        id="subdomain"
                        label={t('register.subdomain')}
                        value={data.subdomain}
                        error={errors.subdomain}
                        onChange={(value) => setData('subdomain', value)}
                        autoComplete="off"
                    />
                    <Field
                        id="ownerEmail"
                        label={t('register.ownerEmail')}
                        type="email"
                        value={data.ownerEmail}
                        error={errors.ownerEmail}
                        onChange={(value) => setData('ownerEmail', value)}
                        autoComplete="email"
                    />

                    <fieldset>
                        <legend className="block text-sm font-medium">{t('register.plan')}</legend>
                        <div className="mt-2 space-y-2" role="radiogroup" aria-label={t('register.plan')}>
                            {plans.map((plan) => {
                                const selected = data.plan === plan.value;
                                return (
                                    <label
                                        key={plan.value}
                                        className={`flex cursor-pointer items-start justify-between gap-3 rounded-lg border p-3 transition ${
                                            selected
                                                ? 'border-brand-500 bg-brand-50 dark:border-brand-500 dark:bg-brand-950/40'
                                                : 'border-slate-300 hover:border-slate-400 dark:border-slate-700 dark:hover:border-slate-600'
                                        }`}
                                    >
                                        <span className="flex items-start gap-3">
                                            <input
                                                type="radio"
                                                name="plan"
                                                value={plan.value}
                                                checked={selected}
                                                onChange={() =>
                                                    setData('plan', plan.value as CreateTenantData['plan'])
                                                }
                                                className="mt-1 h-4 w-4 accent-brand-600"
                                            />
                                            <span className="leading-tight">
                                                <span className="block text-sm font-semibold">{plan.label}</span>
                                                <span className="block text-xs text-slate-500 dark:text-slate-400">
                                                    {formatSeats(plan.seat_limit)}
                                                </span>
                                            </span>
                                        </span>
                                        <span className="whitespace-nowrap text-sm font-semibold">
                                            {formatPrice(plan.price_cents)}
                                        </span>
                                    </label>
                                );
                            })}
                        </div>
                        {errors.plan ? (
                            <p role="alert" className="mt-1 text-sm text-rose-600 dark:text-rose-400">
                                {errors.plan}
                            </p>
                        ) : null}
                    </fieldset>

                    <button
                        type="submit"
                        disabled={processing}
                        className="inline-flex w-full items-center justify-center rounded-lg bg-brand-600 px-4 py-2.5 font-medium text-white transition hover:bg-brand-700 disabled:opacity-60"
                    >
                        {processing ? t('register.submitting') : t('register.submit')}
                    </button>
                </form>
            </section>
        </AppLayout>
    );
}

type FieldProps = {
    id: string;
    label: string;
    value: string;
    error?: string;
    type?: string;
    autoComplete?: string;
    onChange: (value: string) => void;
};

function Field({ id, label, value, error, type = 'text', autoComplete, onChange }: FieldProps) {
    const errorId = `${id}-error`;
    return (
        <div>
            <label htmlFor={id} className="block text-sm font-medium">
                {label}
            </label>
            <input
                id={id}
                name={id}
                type={type}
                value={value}
                autoComplete={autoComplete}
                onChange={(event) => onChange(event.target.value)}
                aria-invalid={error ? true : undefined}
                aria-describedby={error ? errorId : undefined}
                className="mt-1 block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-500 dark:border-slate-700 dark:bg-slate-900"
            />
            {error ? (
                <p id={errorId} role="alert" className="mt-1 text-sm text-rose-600 dark:text-rose-400">
                    {error}
                </p>
            ) : null}
        </div>
    );
}
