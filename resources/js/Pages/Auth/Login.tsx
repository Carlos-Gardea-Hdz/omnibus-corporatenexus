import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { FlashMessages } from '@/Components/FlashMessages';
import { AppLayout } from '@/Layouts/AppLayout';
import { useI18n } from '@/lib/i18n';
import type { TenantData } from '@/types';

type LoginProps = {
    tenant: TenantData;
};

/**
 * Tenant-context sign-in. Rendered AFTER tenancy identification on the tenant
 * subdomain, so the session guard authenticates against the TENANT users table
 * (cross-tenant auth isolation — a user of A can never sign in on B). The form
 * shape matches the server-authoritative LoginData DTO; validation is
 * server-side (302 + session errors, never 422). Password is never echoed back
 * into a prop — only the email is preserved on error (useForm default).
 */
export default function Login({ tenant }: LoginProps) {
    const { t } = useI18n();

    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post('/login', {
            onFinish: () => reset('password'),
        });
    };

    return (
        <AppLayout>
            <Head title={t('auth.login.title')} />

            <section className="mx-auto max-w-md">
                <h1 className="text-2xl font-bold">{t('auth.login.title')}</h1>
                <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">{tenant.name}</p>

                <FlashMessages />

                <form onSubmit={submit} className="mt-6 space-y-5" noValidate>
                    <div>
                        <label htmlFor="email" className="block text-sm font-medium">
                            {t('auth.login.email')}
                        </label>
                        <input
                            id="email"
                            name="email"
                            type="email"
                            value={data.email}
                            autoComplete="username"
                            autoFocus
                            required
                            onChange={(event) => setData('email', event.target.value)}
                            aria-invalid={errors.email ? true : undefined}
                            aria-describedby={errors.email ? 'email-error' : undefined}
                            className="mt-1 block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-500 dark:border-slate-700 dark:bg-slate-900"
                        />
                        {errors.email ? (
                            <p
                                id="email-error"
                                role="alert"
                                className="mt-1 text-sm text-rose-600 dark:text-rose-400"
                            >
                                {errors.email}
                            </p>
                        ) : null}
                    </div>

                    <div>
                        <label htmlFor="password" className="block text-sm font-medium">
                            {t('auth.login.password')}
                        </label>
                        <input
                            id="password"
                            name="password"
                            type="password"
                            value={data.password}
                            autoComplete="current-password"
                            required
                            onChange={(event) => setData('password', event.target.value)}
                            aria-invalid={errors.password ? true : undefined}
                            aria-describedby={errors.password ? 'password-error' : undefined}
                            className="mt-1 block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-500 dark:border-slate-700 dark:bg-slate-900"
                        />
                        {errors.password ? (
                            <p
                                id="password-error"
                                role="alert"
                                className="mt-1 text-sm text-rose-600 dark:text-rose-400"
                            >
                                {errors.password}
                            </p>
                        ) : null}
                    </div>

                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            name="remember"
                            checked={data.remember}
                            onChange={(event) => setData('remember', event.target.checked)}
                            className="h-4 w-4 rounded border-slate-300 accent-brand-600 dark:border-slate-700"
                        />
                        {t('auth.login.remember')}
                    </label>

                    <button
                        type="submit"
                        disabled={processing}
                        className="inline-flex w-full items-center justify-center rounded-lg bg-brand-600 px-4 py-2.5 font-medium text-white transition hover:bg-brand-700 disabled:opacity-60"
                    >
                        {processing ? t('auth.login.submitting') : t('auth.login.submit')}
                    </button>
                </form>
            </section>
        </AppLayout>
    );
}
