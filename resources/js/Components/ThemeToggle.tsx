import { useI18n } from '@/lib/i18n';
import { useTheme } from '@/lib/theme';

export function ThemeToggle() {
    const { theme, toggleTheme } = useTheme();
    const { t } = useI18n();

    return (
        <button
            type="button"
            onClick={toggleTheme}
            aria-label={t('theme.toggle')}
            title={t('theme.toggle')}
            className="inline-flex h-9 w-9 items-center justify-center rounded-md border border-slate-200 text-slate-700 transition hover:bg-slate-100 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
        >
            <span aria-hidden="true">{theme === 'dark' ? '☀️' : '🌙'}</span>
        </button>
    );
}
