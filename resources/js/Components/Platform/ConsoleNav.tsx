import { Link, router } from '@inertiajs/react';
import { useI18n } from '@/lib/i18n';

type Admin = {
    id: string;
    name: string;
    email: string;
};

/**
 * Authenticated platform-console sub-navigation: the operator identity, a link
 * back to the tenant list, and the logout control. Logout is a POST
 * (CSRF-protected Inertia visit) to `platform.logout`, never a GET link. This
 * is the CENTRAL operator console — distinct from the tenant `TenantNav`.
 */
export function ConsoleNav({ admin }: { admin: Admin }) {
    const { t } = useI18n();

    const logout = () => {
        router.post('/admin/logout');
    };

    return (
        <nav
            aria-label={t('platform.console')}
            className="mb-8 flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 pb-4 dark:border-slate-800"
        >
            <div className="flex items-center gap-3">
                <Link
                    href="/admin"
                    className="rounded-md px-3 py-1.5 text-sm font-medium text-slate-700 transition hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-800"
                >
                    {t('platform.dashboard.title')}
                </Link>
                <span className="text-xs text-slate-500 dark:text-slate-400">
                    {admin.name} · {admin.email}
                </span>
            </div>

            <button
                type="button"
                onClick={logout}
                className="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 transition hover:bg-slate-100 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
            >
                {t('platform.logout')}
            </button>
        </nav>
    );
}
