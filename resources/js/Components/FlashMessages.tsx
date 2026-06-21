import { usePage } from '@inertiajs/react';

/**
 * Renders the shared `flash.success` / `flash.error` banners (see the
 * `sharedPageProps.flash` augmentation in types/inertia.d.ts). Server copy is
 * already localized, so it is surfaced verbatim. `role`/`aria-live` announce
 * the message to assistive tech without stealing focus (WCAG 2.2).
 */
export function FlashMessages() {
    const { flash } = usePage().props;

    if (!flash?.success && !flash?.error) {
        return null;
    }

    return (
        <div className="mb-6 space-y-3">
            {flash.success ? (
                <p
                    role="status"
                    aria-live="polite"
                    className="rounded-lg border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/50 dark:text-emerald-300"
                >
                    {flash.success}
                </p>
            ) : null}
            {flash.error ? (
                <p
                    role="alert"
                    aria-live="assertive"
                    className="rounded-lg border border-rose-300 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-800 dark:border-rose-900 dark:bg-rose-950/50 dark:text-rose-300"
                >
                    {flash.error}
                </p>
            ) : null}
        </div>
    );
}
