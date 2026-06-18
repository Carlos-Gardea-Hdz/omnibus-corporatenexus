import { Head, Link } from '@inertiajs/react';
import { AppLayout } from '@/Layouts/AppLayout';
import { useI18n } from '@/lib/i18n';

export default function Welcome() {
    const { t } = useI18n();

    return (
        <AppLayout>
            <Head title={t('app.name')} />

            <section className="mx-auto max-w-2xl text-center">
                <h1 className="text-4xl font-bold tracking-tight sm:text-5xl">{t('app.name')}</h1>
                <p className="mt-4 text-lg text-slate-600 dark:text-slate-300">{t('app.tagline')}</p>

                <div className="mt-8">
                    <Link
                        href="/register"
                        className="inline-flex items-center justify-center rounded-lg bg-brand-600 px-6 py-3 font-medium text-white transition hover:bg-brand-700"
                    >
                        {t('welcome.cta')}
                    </Link>
                </div>
            </section>
        </AppLayout>
    );
}
