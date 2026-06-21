import type { TenantStatus } from '@/types';

/**
 * Tenant status pill for the platform console. The label and color are
 * server-resolved (`status_label` / `status_color` from the `TenantStatus`
 * enum helpers) — the client never re-derives copy or color from the raw enum
 * value, keeping the backed enum the single source of truth. `status_color` is
 * a semantic token (the `TenantStatus::color()` set — emerald/amber/red/rose/
 * slate) mapped to Tailwind utility classes here. An unknown token falls back
 * to slate so a new enum case never renders an unstyled pill.
 */

type StatusColor = string;

const COLOR_CLASSES: Record<string, string> = {
    emerald:
        'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300',
    amber: 'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300',
    red: 'bg-red-100 text-red-800 dark:bg-red-950/60 dark:text-red-300',
    rose: 'bg-rose-100 text-rose-800 dark:bg-rose-950/60 dark:text-rose-300',
    slate: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
};

export function StatusBadge({
    status,
    label,
    color,
}: {
    status: TenantStatus;
    label: string;
    color: StatusColor;
}) {
    const classes = COLOR_CLASSES[color] ?? COLOR_CLASSES.slate;

    return (
        <span
            data-status={status}
            className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold ${classes}`}
        >
            {label}
        </span>
    );
}
