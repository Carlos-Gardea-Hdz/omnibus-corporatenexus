/**
 * A small pill whose accent comes from a server-resolved HEX color — the
 * `ProjectStatus`/`TaskStatus`/`TaskPriority` enum `color()` helpers return hex
 * tokens (e.g. `#2563EB`), so the client never re-derives copy or color from
 * the raw enum value; the backed enum stays the single source of truth. The hex
 * drives a leading dot + a subtle tinted ring via inline style (Tailwind cannot
 * express an arbitrary runtime hex as a utility), while the neutral chrome stays
 * theme-aware for dark/light. A decorative dot (`aria-hidden`) carries the color
 * so meaning never relies on color alone (WCAG 2.2 1.4.1): the label is the
 * accessible text.
 */
export function ColorBadge({ label, color }: { label: string; color: string }) {
    return (
        <span
            className="inline-flex items-center gap-1.5 rounded-full border border-slate-200 bg-white px-2.5 py-0.5 text-xs font-semibold text-slate-700 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200"
            style={{ borderColor: `${color}66` }}
        >
            <span
                aria-hidden="true"
                className="inline-block h-2 w-2 shrink-0 rounded-full"
                style={{ backgroundColor: color }}
            />
            {label}
        </span>
    );
}
