import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { FlashMessages } from '@/Components/FlashMessages';
import { TenantNav } from '@/Components/TenantNav';
import { AppLayout } from '@/Layouts/AppLayout';
import { useI18n } from '@/lib/i18n';
import type { MemberRole } from '@/types';

type MemberRow = {
    id: number;
    name: string;
    email: string;
    role: MemberRole;
    role_label: string;
    is_self: boolean;
    is_owner: boolean;
};

type AssignableRole = {
    value: MemberRole;
    label: string;
};

type MembersProps = {
    members: MemberRow[];
    seat_limit: number;
    seat_used: number;
    seats_remaining: number | null;
    assignable_roles: AssignableRole[];
    can: {
        manage_members: boolean;
        invite: boolean;
        transfer_ownership: boolean;
    };
};

/**
 * Tenant member management. All mutations are server-authoritative Inertia
 * visits (302 + flash/session errors, never 422). Capabilities are computed
 * server-side (`can.*`, `assignable_roles`) so the UI only ever offers actions
 * the actor may actually perform — a `member` sees no controls, and admins
 * cannot target/assign `owner`. No password reaches a prop: the one-time temp
 * password for a freshly invited member arrives via `flash.success`.
 */
export default function Index({
    members,
    seat_limit,
    seat_used,
    seats_remaining,
    assignable_roles,
    can,
}: MembersProps) {
    const { t } = useI18n();
    const { flash } = usePage().props;
    const tempPassword = flash?.temp_password ?? null;

    const seatLabel =
        seat_limit === 0
            ? `${seat_used} ${t('members.seat.used')} · ${t('members.seat.unlimited')}`
            : `${seat_used} / ${seat_limit} ${t('members.seat.used')} · ${seats_remaining ?? 0} ${t(
                  'members.seat.remaining',
              )}`;

    const seatPct =
        seat_limit === 0 ? 0 : Math.min(100, Math.round((seat_used / seat_limit) * 100));

    return (
        <AppLayout>
            <Head title={t('members.title')} />

            <TenantNav current="members" />
            <FlashMessages />

            {tempPassword ? <MemberCredential password={tempPassword} /> : null}

            <section>
                <header className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-bold">{t('members.title')}</h1>
                        <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
                            {t('members.subtitle')}
                        </p>
                    </div>
                    <div className="text-right">
                        <p className="text-sm font-medium">{seatLabel}</p>
                        {seat_limit > 0 ? (
                            <div
                                className="mt-1 h-2 w-44 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-800"
                                role="progressbar"
                                aria-valuemin={0}
                                aria-valuemax={seat_limit}
                                aria-valuenow={seat_used}
                                aria-label={t('dashboard.seats')}
                            >
                                <div
                                    className="h-full rounded-full bg-brand-600 transition-[width]"
                                    style={{ width: `${seatPct}%` }}
                                />
                            </div>
                        ) : null}
                    </div>
                </header>

                {can.invite ? <InviteForm assignableRoles={assignable_roles} /> : null}

                <MembersTable
                    members={members}
                    canManage={can.manage_members}
                    assignableRoles={assignable_roles}
                />
            </section>
        </AppLayout>
    );
}

/**
 * One-time reveal of a freshly invited member's temporary password — mirrors
 * the Provisioning OwnerCredential. It arrives only via the transient
 * `flash.temp_password` (never a list prop, never logged), so the inviter can
 * relay it once; a normal list render carries nothing.
 */
function MemberCredential({ password }: { password: string }) {
    const { t } = useI18n();
    const [copied, setCopied] = useState(false);

    const copy = () => {
        void navigator.clipboard?.writeText(password).then(() => {
            setCopied(true);
            window.setTimeout(() => setCopied(false), 2000);
        });
    };

    return (
        <div className="mb-6 w-full rounded-xl border border-amber-300 bg-amber-50 p-4 text-left dark:border-amber-900 dark:bg-amber-950/40">
            <h2 className="text-sm font-semibold text-amber-900 dark:text-amber-200">
                {t('members.temp_password.title')}
            </h2>
            <p className="mt-1 text-xs text-amber-800 dark:text-amber-300">
                {t('members.temp_password.hint')}
            </p>
            <div className="mt-3 flex items-center gap-2">
                <code className="flex-1 rounded-md border border-amber-300 bg-white px-3 py-2 font-mono text-sm text-slate-900 dark:border-amber-900 dark:bg-slate-900 dark:text-slate-100">
                    {password}
                </code>
                <button
                    type="button"
                    onClick={copy}
                    className="rounded-md border border-amber-400 px-3 py-2 text-sm font-medium text-amber-900 transition hover:bg-amber-100 dark:border-amber-800 dark:text-amber-200 dark:hover:bg-amber-950/60"
                >
                    {copied ? t('members.temp_password.copied') : t('members.temp_password.copy')}
                </button>
            </div>
        </div>
    );
}

function InviteForm({ assignableRoles }: { assignableRoles: AssignableRole[] }) {
    const { t } = useI18n();
    const defaultRole: MemberRole =
        assignableRoles.find((r) => r.value === 'member')?.value ??
        assignableRoles[0]?.value ??
        'member';

    const { data, setData, post, processing, errors, reset } = useForm<{
        name: string;
        email: string;
        role: MemberRole;
    }>({
        name: '',
        email: '',
        role: defaultRole,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post('/members', {
            preserveScroll: true,
            onSuccess: () => reset(),
        });
    };

    return (
        <form
            onSubmit={submit}
            noValidate
            className="mt-8 rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900"
        >
            <h2 className="text-sm font-semibold">{t('members.invite')}</h2>

            <div className="mt-4 grid gap-4 sm:grid-cols-3">
                <div>
                    <label htmlFor="invite-name" className="block text-sm font-medium">
                        {t('members.invite.name')}
                    </label>
                    <input
                        id="invite-name"
                        name="name"
                        type="text"
                        value={data.name}
                        autoComplete="name"
                        onChange={(event) => setData('name', event.target.value)}
                        aria-invalid={errors.name ? true : undefined}
                        aria-describedby={errors.name ? 'invite-name-error' : undefined}
                        className="mt-1 block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-500 dark:border-slate-700 dark:bg-slate-900"
                    />
                    {errors.name ? (
                        <p
                            id="invite-name-error"
                            role="alert"
                            className="mt-1 text-sm text-rose-600 dark:text-rose-400"
                        >
                            {errors.name}
                        </p>
                    ) : null}
                </div>

                <div>
                    <label htmlFor="invite-email" className="block text-sm font-medium">
                        {t('members.invite.email')}
                    </label>
                    <input
                        id="invite-email"
                        name="email"
                        type="email"
                        value={data.email}
                        autoComplete="email"
                        onChange={(event) => setData('email', event.target.value)}
                        aria-invalid={errors.email ? true : undefined}
                        aria-describedby={errors.email ? 'invite-email-error' : undefined}
                        className="mt-1 block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-500 dark:border-slate-700 dark:bg-slate-900"
                    />
                    {errors.email ? (
                        <p
                            id="invite-email-error"
                            role="alert"
                            className="mt-1 text-sm text-rose-600 dark:text-rose-400"
                        >
                            {errors.email}
                        </p>
                    ) : null}
                </div>

                <div>
                    <label htmlFor="invite-role" className="block text-sm font-medium">
                        {t('members.invite.role')}
                    </label>
                    <select
                        id="invite-role"
                        name="role"
                        value={data.role}
                        onChange={(event) => setData('role', event.target.value as MemberRole)}
                        aria-invalid={errors.role ? true : undefined}
                        aria-describedby={errors.role ? 'invite-role-error' : undefined}
                        className="mt-1 block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-500 dark:border-slate-700 dark:bg-slate-900"
                    >
                        {assignableRoles.map((role) => (
                            <option key={role.value} value={role.value}>
                                {role.label}
                            </option>
                        ))}
                    </select>
                    {errors.role ? (
                        <p
                            id="invite-role-error"
                            role="alert"
                            className="mt-1 text-sm text-rose-600 dark:text-rose-400"
                        >
                            {errors.role}
                        </p>
                    ) : null}
                </div>
            </div>

            <div className="mt-4">
                <button
                    type="submit"
                    disabled={processing}
                    className="inline-flex items-center justify-center rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-brand-700 disabled:opacity-60"
                >
                    {processing ? t('members.invite.submitting') : t('members.invite.submit')}
                </button>
            </div>
        </form>
    );
}

function MembersTable({
    members,
    canManage,
    assignableRoles,
}: {
    members: MemberRow[];
    canManage: boolean;
    assignableRoles: AssignableRole[];
}) {
    const { t } = useI18n();

    return (
        <div className="mt-8 overflow-hidden rounded-xl border border-slate-200 dark:border-slate-800">
            <table className="w-full text-left text-sm">
                <thead className="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-slate-900/60 dark:text-slate-400">
                    <tr>
                        <th scope="col" className="px-4 py-3 font-medium">
                            {t('members.table.name')}
                        </th>
                        <th scope="col" className="px-4 py-3 font-medium">
                            {t('members.table.email')}
                        </th>
                        <th scope="col" className="px-4 py-3 font-medium">
                            {t('members.table.role')}
                        </th>
                        {canManage ? (
                            <th scope="col" className="px-4 py-3 text-right font-medium">
                                {t('members.table.actions')}
                            </th>
                        ) : null}
                    </tr>
                </thead>
                <tbody className="divide-y divide-slate-200 bg-white dark:divide-slate-800 dark:bg-slate-950">
                    {members.length === 0 ? (
                        <tr>
                            <td
                                colSpan={canManage ? 4 : 3}
                                className="px-4 py-8 text-center text-sm text-slate-500 dark:text-slate-400"
                            >
                                {t('members.empty')}
                            </td>
                        </tr>
                    ) : (
                        members.map((member) => (
                            <MemberRowView
                                key={member.id}
                                member={member}
                                canManage={canManage}
                                assignableRoles={assignableRoles}
                            />
                        ))
                    )}
                </tbody>
            </table>
        </div>
    );
}

function MemberRowView({
    member,
    canManage,
    assignableRoles,
}: {
    member: MemberRow;
    canManage: boolean;
    assignableRoles: AssignableRole[];
}) {
    const { t } = useI18n();
    const [updating, setUpdating] = useState(false);
    const [removing, setRemoving] = useState(false);

    // An owner row can only be retargeted via a transfer (promoting another to
    // owner) — never demoted in place — so the owner's own role select is locked.
    const roleEditable = canManage && assignableRoles.length > 0 && !member.is_owner;

    const changeRole = (role: MemberRole) => {
        router.patch(
            `/members/${member.id}`,
            { role },
            {
                preserveScroll: true,
                onStart: () => setUpdating(true),
                onFinish: () => setUpdating(false),
            },
        );
    };

    const remove = () => {
        if (!window.confirm(t('members.remove.confirm'))) {
            return;
        }
        router.delete(`/members/${member.id}`, {
            preserveScroll: true,
            onStart: () => setRemoving(true),
            onFinish: () => setRemoving(false),
        });
    };

    return (
        <tr>
            <td className="px-4 py-3 font-medium">
                <span className="flex items-center gap-2">
                    {member.name}
                    {member.is_self ? (
                        <span className="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                            {t('members.you_badge')}
                        </span>
                    ) : null}
                </span>
            </td>
            <td className="px-4 py-3 text-slate-600 dark:text-slate-400">{member.email}</td>
            <td className="px-4 py-3">
                {roleEditable ? (
                    <label className="sr-only" htmlFor={`role-${member.id}`}>
                        {t('members.update_role')}
                    </label>
                ) : null}
                {roleEditable ? (
                    <select
                        id={`role-${member.id}`}
                        value={member.role}
                        disabled={updating}
                        onChange={(event) => changeRole(event.target.value as MemberRole)}
                        className="rounded-md border border-slate-300 bg-white px-2 py-1 text-sm focus:border-brand-500 disabled:opacity-60 dark:border-slate-700 dark:bg-slate-900"
                    >
                        {assignableRoles.map((role) => (
                            <option key={role.value} value={role.value}>
                                {role.label}
                            </option>
                        ))}
                    </select>
                ) : (
                    <span className="inline-flex items-center rounded-full bg-brand-50 px-2.5 py-0.5 text-xs font-semibold text-brand-700 dark:bg-brand-950/50 dark:text-brand-300">
                        {member.role_label}
                    </span>
                )}
            </td>
            {canManage ? (
                <td className="px-4 py-3 text-right">
                    {!member.is_self && !member.is_owner ? (
                        <button
                            type="button"
                            onClick={remove}
                            disabled={removing}
                            className="rounded-md border border-rose-300 px-2.5 py-1 text-sm font-medium text-rose-700 transition hover:bg-rose-50 disabled:opacity-60 dark:border-rose-900 dark:text-rose-300 dark:hover:bg-rose-950/40"
                        >
                            {t('members.remove')}
                        </button>
                    ) : (
                        <span className="text-xs text-slate-400 dark:text-slate-600">—</span>
                    )}
                </td>
            ) : null}
        </tr>
    );
}
