import { useI18n } from '@/lib/i18n';

export function LocaleToggle() {
    const { locale, setLocale, t } = useI18n();

    return (
        <button
            type="button"
            onClick={() => setLocale(locale === 'es' ? 'en' : 'es')}
            aria-label={t('lang.toggle')}
            title={t('lang.toggle')}
            className="inline-flex h-9 items-center justify-center rounded-md border border-slate-200 px-3 text-sm font-medium text-slate-700 uppercase transition hover:bg-slate-100 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
        >
            {locale === 'es' ? 'EN' : 'ES'}
        </button>
    );
}
