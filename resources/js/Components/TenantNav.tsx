import { Link, router } from '@inertiajs/react';
import { useI18n } from '@/lib/i18n';

/**
 * Authenticated tenant sub-navigation: links between the tenant pages and the
 * logout control. Logout is a POST (CSRF-protected Inertia visit) to
 * `tenant.logout`, never a GET link. `current` highlights the active page for
 * orientation (aria-current for assistive tech).
 */
export function TenantNav({ current }: { current: 'dashboard' | 'members' }) {
    const { t } = useI18n();

    const logout = () => {
        router.post('/logout');
    };

    const linkClass = (active: boolean): string =>
        `rounded-md px-3 py-1.5 text-sm font-medium transition ${
            active
                ? 'bg-brand-600 text-white'
                : 'text-slate-700 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-800'
        }`;

    return (
        <nav
            aria-label="Tenant"
            className="mb-8 flex items-center justify-between gap-3 border-b border-slate-200 pb-4 dark:border-slate-800"
        >
            <div className="flex items-center gap-1">
                <Link
                    href="/dashboard"
                    className={linkClass(current === 'dashboard')}
                    aria-current={current === 'dashboard' ? 'page' : undefined}
                >
                    {t('nav.dashboard')}
                </Link>
                <Link
                    href="/members"
                    className={linkClass(current === 'members')}
                    aria-current={current === 'members' ? 'page' : undefined}
                >
                    {t('members.title')}
                </Link>
            </div>

            <button
                type="button"
                onClick={logout}
                className="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 transition hover:bg-slate-100 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
            >
                {t('auth.logout')}
            </button>
        </nav>
    );
}
