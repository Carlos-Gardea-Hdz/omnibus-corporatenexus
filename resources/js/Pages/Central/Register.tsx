import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { AppLayout } from '@/Layouts/AppLayout';
import { useI18n } from '@/lib/i18n';
import type { CreateTenantData } from '@/types';

/**
 * Tenant self-registration. Validation is server-authoritative (the matching
 * rules live on CreateTenantData); the form shape is the generated TS type so
 * the client never re-declares it (inertia-react §2, §4).
 */
export default function Register() {
    const { t } = useI18n();

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
