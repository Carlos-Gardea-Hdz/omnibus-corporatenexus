import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { FlashMessages } from '@/Components/FlashMessages';
import { TenantNav } from '@/Components/TenantNav';
import { ColorBadge } from '@/Components/Work/ColorBadge';
import { AppLayout } from '@/Layouts/AppLayout';
import { useI18n } from '@/lib/i18n';

/**
 * Project board (tenant-scoped). One column per `TaskStatus`; each task carries
 * only the transitions the state machine allows (`available_transitions`,
 * resolved server-side from the backed enum), so the UI can never offer an
 * illegal edge — an illegal attempt would 302 + flash anyway. Project lifecycle
 * controls (rename, status transition) are gated to admin+ (`can.manage_projects`).
 * Task actions (create, transition, assign, edit) are open to any member.
 * Assignment targets `members` — the THIS-tenant assignable list (the member
 * guard's source of truth). An archived project is read-only for tasks
 * (`is_archived`): the server rejects task writes; the UI hides their controls.
 * All mutations are server-authoritative Inertia visits (302 + session errors,
 * never 422).
 */

type Transition = { value: string; label: string };

type Assignee = { id: number; name: string };

type TaskCard = {
    id: string;
    title: string;
    description: string | null;
    status: string;
    priority: string;
    priority_label: string;
    priority_color: string;
    assignee: Assignee | null;
    due_date: string | null;
    available_transitions: Transition[];
};

type Column = {
    status: string;
    label: string;
    color: string;
    tasks: TaskCard[];
};

type ProjectHead = {
    id: string;
    name: string;
    description: string | null;
    status: string;
    status_label: string;
    status_color: string;
    is_archived: boolean;
    available_transitions: Transition[];
};

type PriorityOption = { value: string; label: string; color: string };

type ShowProps = {
    project: ProjectHead;
    columns: Column[];
    members: Assignee[];
    priorities: PriorityOption[];
    filters: { status: string | null; assignee: number | null };
    can: { manage_projects: boolean };
};

export default function Show({ project, columns, members, priorities, filters, can }: ShowProps) {
    const { t } = useI18n();

    return (
        <AppLayout>
            <Head title={project.name} />

            <TenantNav current="projects" />
            <FlashMessages />

            <section>
                <Link
                    href="/projects"
                    className="text-sm font-medium text-brand-600 transition hover:text-brand-700 dark:text-brand-400"
                >
                    ← {t('work.projects.back')}
                </Link>

                <ProjectHeader project={project} canManage={can.manage_projects} />

                {can.manage_projects ? <EditProjectForm project={project} /> : null}

                <TaskFilters
                    filters={filters}
                    columns={columns}
                    members={members}
                    projectId={project.id}
                />

                {project.is_archived ? (
                    <p
                        role="status"
                        className="mt-6 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-300"
                    >
                        {t('work.project.archived_notice')}
                    </p>
                ) : (
                    <CreateTaskForm
                        projectId={project.id}
                        priorities={priorities}
                        members={members}
                    />
                )}

                <Board
                    project={project}
                    columns={columns}
                    members={members}
                    priorities={priorities}
                />
            </section>
        </AppLayout>
    );
}

function ProjectHeader({ project, canManage }: { project: ProjectHead; canManage: boolean }) {
    const { t } = useI18n();

    const transition = (status: string) => {
        router.patch(`/projects/${project.id}/status`, { status }, { preserveScroll: true });
    };

    return (
        <header className="mt-3 flex flex-wrap items-start justify-between gap-4">
            <div>
                <div className="flex items-center gap-3">
                    <h1 className="text-2xl font-bold">{project.name}</h1>
                    <ColorBadge label={project.status_label} color={project.status_color} />
                </div>
                {project.description ? (
                    <p className="mt-1 max-w-2xl text-sm text-slate-500 dark:text-slate-400">
                        {project.description}
                    </p>
                ) : null}
            </div>

            {canManage && project.available_transitions.length > 0 ? (
                <div className="flex flex-col items-end gap-1">
                    <span className="text-xs font-medium text-slate-500 dark:text-slate-400">
                        {t('work.project.transition')}
                    </span>
                    <div className="flex flex-wrap justify-end gap-2">
                        {project.available_transitions.map((next) => (
                            <button
                                key={next.value}
                                type="button"
                                onClick={() => transition(next.value)}
                                className="rounded-md border border-slate-300 px-2.5 py-1 text-sm font-medium text-slate-700 transition hover:bg-slate-100 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                            >
                                {next.label}
                            </button>
                        ))}
                    </div>
                </div>
            ) : null}
        </header>
    );
}

function EditProjectForm({ project }: { project: ProjectHead }) {
    const { t } = useI18n();
    const [open, setOpen] = useState(false);

    const { data, setData, patch, processing, errors } = useForm<{
        name: string;
        description: string;
    }>({
        name: project.name,
        description: project.description ?? '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        patch(`/projects/${project.id}`, {
            preserveScroll: true,
            onSuccess: () => setOpen(false),
        });
    };

    return (
        <div className="mt-4">
            <button
                type="button"
                onClick={() => setOpen((value) => !value)}
                aria-expanded={open}
                className="text-sm font-medium text-brand-600 transition hover:text-brand-700 dark:text-brand-400"
            >
                {open ? t('work.project.edit.cancel') : t('work.project.edit')}
            </button>

            {open ? (
                <form
                    onSubmit={submit}
                    noValidate
                    className="mt-3 rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900"
                >
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label
                                htmlFor="edit-project-name"
                                className="block text-sm font-medium"
                            >
                                {t('work.project.name')}
                            </label>
                            <input
                                id="edit-project-name"
                                type="text"
                                value={data.name}
                                onChange={(event) => setData('name', event.target.value)}
                                aria-invalid={errors.name ? true : undefined}
                                aria-describedby={
                                    errors.name ? 'edit-project-name-error' : undefined
                                }
                                className="mt-1 block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-500 dark:border-slate-700 dark:bg-slate-900"
                            />
                            {errors.name ? (
                                <p
                                    id="edit-project-name-error"
                                    role="alert"
                                    className="mt-1 text-sm text-rose-600 dark:text-rose-400"
                                >
                                    {errors.name}
                                </p>
                            ) : null}
                        </div>
                        <div>
                            <label
                                htmlFor="edit-project-description"
                                className="block text-sm font-medium"
                            >
                                {t('work.project.description')}
                            </label>
                            <input
                                id="edit-project-description"
                                type="text"
                                value={data.description}
                                onChange={(event) => setData('description', event.target.value)}
                                aria-invalid={errors.description ? true : undefined}
                                aria-describedby={
                                    errors.description
                                        ? 'edit-project-description-error'
                                        : undefined
                                }
                                className="mt-1 block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-500 dark:border-slate-700 dark:bg-slate-900"
                            />
                            {errors.description ? (
                                <p
                                    id="edit-project-description-error"
                                    role="alert"
                                    className="mt-1 text-sm text-rose-600 dark:text-rose-400"
                                >
                                    {errors.description}
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
                            {processing ? t('work.saving') : t('work.project.edit.submit')}
                        </button>
                    </div>
                </form>
            ) : null}
        </div>
    );
}

function TaskFilters({
    filters,
    columns,
    members,
    projectId,
}: {
    filters: { status: string | null; assignee: number | null };
    columns: Column[];
    members: Assignee[];
    projectId: string;
}) {
    const { t } = useI18n();
    const [status, setStatus] = useState(filters.status ?? '');
    const [assignee, setAssignee] = useState(filters.assignee ? String(filters.assignee) : '');

    const apply = (event: FormEvent) => {
        event.preventDefault();
        const query: Record<string, string> = {};
        if (status) {
            query.status = status;
        }
        if (assignee) {
            query.assignee = assignee;
        }
        router.get(`/projects/${projectId}`, query, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const clear = () => {
        setStatus('');
        setAssignee('');
        router.get(`/projects/${projectId}`, {}, { preserveScroll: true });
    };

    return (
        <form
            onSubmit={apply}
            className="mt-6 flex flex-wrap items-end gap-4 rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900"
        >
            <div>
                <label htmlFor="filter-task-status" className="block text-sm font-medium">
                    {t('work.task.status')}
                </label>
                <select
                    id="filter-task-status"
                    value={status}
                    onChange={(event) => setStatus(event.target.value)}
                    className="mt-1 block w-44 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-500 dark:border-slate-700 dark:bg-slate-900"
                >
                    <option value="">{t('work.filters.all')}</option>
                    {columns.map((column) => (
                        <option key={column.status} value={column.status}>
                            {column.label}
                        </option>
                    ))}
                </select>
            </div>

            <div>
                <label htmlFor="filter-task-assignee" className="block text-sm font-medium">
                    {t('work.task.assignee')}
                </label>
                <select
                    id="filter-task-assignee"
                    value={assignee}
                    onChange={(event) => setAssignee(event.target.value)}
                    className="mt-1 block w-52 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-500 dark:border-slate-700 dark:bg-slate-900"
                >
                    <option value="">{t('work.filters.all')}</option>
                    {members.map((member) => (
                        <option key={member.id} value={String(member.id)}>
                            {member.name}
                        </option>
                    ))}
                </select>
            </div>

            <div className="flex items-center gap-2">
                <button
                    type="submit"
                    className="inline-flex items-center justify-center rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-brand-700"
                >
                    {t('work.filters.apply')}
                </button>
                {filters.status || filters.assignee ? (
                    <button
                        type="button"
                        onClick={clear}
                        className="inline-flex items-center justify-center rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-100 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                    >
                        {t('work.filters.clear')}
                    </button>
                ) : null}
            </div>
        </form>
    );
}

function CreateTaskForm({
    projectId,
    priorities,
    members,
}: {
    projectId: string;
    priorities: PriorityOption[];
    members: Assignee[];
}) {
    const { t } = useI18n();

    const defaultPriority =
        priorities.find((p) => p.value === 'medium')?.value ?? priorities[0]?.value ?? 'medium';

    const { data, setData, post, processing, errors, reset } = useForm<{
        title: string;
        description: string;
        priority: string;
        assigned_to: string;
        due_date: string;
    }>({
        title: '',
        description: '',
        priority: defaultPriority,
        assigned_to: '',
        due_date: '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(`/projects/${projectId}/tasks`, {
            preserveScroll: true,
            onSuccess: () => reset(),
        });
    };

    return (
        <form
            onSubmit={submit}
            noValidate
            className="mt-6 rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900"
        >
            <h2 className="text-sm font-semibold">{t('work.task.create')}</h2>

            <div className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div className="lg:col-span-2">
                    <label htmlFor="task-title" className="block text-sm font-medium">
                        {t('work.task.title')}
                    </label>
                    <input
                        id="task-title"
                        name="title"
                        type="text"
                        value={data.title}
                        onChange={(event) => setData('title', event.target.value)}
                        aria-invalid={errors.title ? true : undefined}
                        aria-describedby={errors.title ? 'task-title-error' : undefined}
                        className="mt-1 block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-500 dark:border-slate-700 dark:bg-slate-900"
                    />
                    {errors.title ? (
                        <p
                            id="task-title-error"
                            role="alert"
                            className="mt-1 text-sm text-rose-600 dark:text-rose-400"
                        >
                            {errors.title}
                        </p>
                    ) : null}
                </div>

                <div>
                    <label htmlFor="task-priority" className="block text-sm font-medium">
                        {t('work.task.priority')}
                    </label>
                    <select
                        id="task-priority"
                        name="priority"
                        value={data.priority}
                        onChange={(event) => setData('priority', event.target.value)}
                        aria-invalid={errors.priority ? true : undefined}
                        aria-describedby={errors.priority ? 'task-priority-error' : undefined}
                        className="mt-1 block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-500 dark:border-slate-700 dark:bg-slate-900"
                    >
                        {priorities.map((priority) => (
                            <option key={priority.value} value={priority.value}>
                                {priority.label}
                            </option>
                        ))}
                    </select>
                    {errors.priority ? (
                        <p
                            id="task-priority-error"
                            role="alert"
                            className="mt-1 text-sm text-rose-600 dark:text-rose-400"
                        >
                            {errors.priority}
                        </p>
                    ) : null}
                </div>

                <div className="lg:col-span-2">
                    <label htmlFor="task-description" className="block text-sm font-medium">
                        {t('work.task.description')}
                    </label>
                    <input
                        id="task-description"
                        name="description"
                        type="text"
                        value={data.description}
                        onChange={(event) => setData('description', event.target.value)}
                        aria-invalid={errors.description ? true : undefined}
                        aria-describedby={errors.description ? 'task-description-error' : undefined}
                        className="mt-1 block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-500 dark:border-slate-700 dark:bg-slate-900"
                    />
                    {errors.description ? (
                        <p
                            id="task-description-error"
                            role="alert"
                            className="mt-1 text-sm text-rose-600 dark:text-rose-400"
                        >
                            {errors.description}
                        </p>
                    ) : null}
                </div>

                <div>
                    <label htmlFor="task-assignee" className="block text-sm font-medium">
                        {t('work.task.assignee')}
                    </label>
                    <select
                        id="task-assignee"
                        name="assigned_to"
                        value={data.assigned_to}
                        onChange={(event) => setData('assigned_to', event.target.value)}
                        aria-invalid={errors.assigned_to ? true : undefined}
                        aria-describedby={errors.assigned_to ? 'task-assignee-error' : undefined}
                        className="mt-1 block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-500 dark:border-slate-700 dark:bg-slate-900"
                    >
                        <option value="">{t('work.task.unassigned')}</option>
                        {members.map((member) => (
                            <option key={member.id} value={String(member.id)}>
                                {member.name}
                            </option>
                        ))}
                    </select>
                    {errors.assigned_to ? (
                        <p
                            id="task-assignee-error"
                            role="alert"
                            className="mt-1 text-sm text-rose-600 dark:text-rose-400"
                        >
                            {errors.assigned_to}
                        </p>
                    ) : null}
                </div>

                <div>
                    <label htmlFor="task-due-date" className="block text-sm font-medium">
                        {t('work.task.due_date')}
                    </label>
                    <input
                        id="task-due-date"
                        name="due_date"
                        type="date"
                        value={data.due_date}
                        onChange={(event) => setData('due_date', event.target.value)}
                        aria-invalid={errors.due_date ? true : undefined}
                        aria-describedby={errors.due_date ? 'task-due-date-error' : undefined}
                        className="mt-1 block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-500 dark:border-slate-700 dark:bg-slate-900"
                    />
                    {errors.due_date ? (
                        <p
                            id="task-due-date-error"
                            role="alert"
                            className="mt-1 text-sm text-rose-600 dark:text-rose-400"
                        >
                            {errors.due_date}
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
                    {processing ? t('work.task.creating') : t('work.task.create.submit')}
                </button>
            </div>
        </form>
    );
}

function Board({
    project,
    columns,
    members,
    priorities,
}: {
    project: ProjectHead;
    columns: Column[];
    members: Assignee[];
    priorities: PriorityOption[];
}) {
    const { t } = useI18n();

    return (
        <div className="mt-8 grid gap-4 md:grid-cols-3">
            {columns.map((column) => (
                <div
                    key={column.status}
                    className="rounded-xl border border-slate-200 bg-slate-50/60 p-3 dark:border-slate-800 dark:bg-slate-900/40"
                >
                    <header className="flex items-center justify-between gap-2 px-1 pb-2">
                        <span className="inline-flex items-center gap-2 text-sm font-semibold">
                            <span
                                aria-hidden="true"
                                className="inline-block h-2.5 w-2.5 rounded-full"
                                style={{ backgroundColor: column.color }}
                            />
                            {column.label}
                        </span>
                        <span className="rounded-full bg-slate-200 px-2 py-0.5 text-xs font-semibold text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                            {column.tasks.length}
                        </span>
                    </header>

                    {column.tasks.length === 0 ? (
                        <p className="px-1 py-6 text-center text-xs text-slate-400 dark:text-slate-600">
                            {t('work.board.column_empty')}
                        </p>
                    ) : (
                        <ul className="space-y-3">
                            {column.tasks.map((task) => (
                                <li key={task.id}>
                                    <TaskCardView
                                        task={task}
                                        project={project}
                                        members={members}
                                        priorities={priorities}
                                    />
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            ))}
        </div>
    );
}

function TaskCardView({
    task,
    project,
    members,
    priorities,
}: {
    task: TaskCard;
    project: ProjectHead;
    members: Assignee[];
    priorities: PriorityOption[];
}) {
    const { t } = useI18n();
    const [editing, setEditing] = useState(false);
    const locked = project.is_archived;

    const transition = (status: string) => {
        router.patch(
            `/projects/${project.id}/tasks/${task.id}/status`,
            { status },
            { preserveScroll: true },
        );
    };

    const assign = (value: string) => {
        router.patch(
            `/projects/${project.id}/tasks/${task.id}/assignee`,
            { assigned_to: value === '' ? null : Number(value) },
            { preserveScroll: true },
        );
    };

    return (
        <article className="rounded-lg border border-slate-200 bg-white p-3 shadow-sm dark:border-slate-800 dark:bg-slate-950">
            <div className="flex items-start justify-between gap-2">
                <h3 className="text-sm font-semibold">{task.title}</h3>
                <ColorBadge label={task.priority_label} color={task.priority_color} />
            </div>

            {task.description ? (
                <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
                    {task.description}
                </p>
            ) : null}

            <dl className="mt-2 space-y-1 text-xs text-slate-500 dark:text-slate-400">
                <div className="flex items-center gap-1">
                    <dt>{t('work.task.assignee')}:</dt>
                    <dd className="font-medium text-slate-700 dark:text-slate-200">
                        {task.assignee ? task.assignee.name : t('work.task.unassigned')}
                    </dd>
                </div>
                {task.due_date ? (
                    <div className="flex items-center gap-1">
                        <dt>{t('work.task.due_date')}:</dt>
                        <dd className="font-medium text-slate-700 dark:text-slate-200">
                            {task.due_date}
                        </dd>
                    </div>
                ) : null}
            </dl>

            {!locked ? (
                <div className="mt-3 space-y-2">
                    {task.available_transitions.length > 0 ? (
                        <div className="flex flex-wrap gap-1.5">
                            {task.available_transitions.map((next) => (
                                <button
                                    key={next.value}
                                    type="button"
                                    onClick={() => transition(next.value)}
                                    className="rounded-md border border-slate-300 px-2 py-0.5 text-xs font-medium text-slate-700 transition hover:bg-slate-100 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                                >
                                    {next.label}
                                </button>
                            ))}
                        </div>
                    ) : null}

                    <div>
                        <label htmlFor={`assign-${task.id}`} className="sr-only">
                            {t('work.task.assign')}
                        </label>
                        <select
                            id={`assign-${task.id}`}
                            value={task.assignee ? String(task.assignee.id) : ''}
                            onChange={(event) => assign(event.target.value)}
                            className="block w-full rounded-md border border-slate-300 bg-white px-2 py-1 text-xs focus:border-brand-500 dark:border-slate-700 dark:bg-slate-900"
                        >
                            <option value="">{t('work.task.unassigned')}</option>
                            {members.map((member) => (
                                <option key={member.id} value={String(member.id)}>
                                    {member.name}
                                </option>
                            ))}
                        </select>
                    </div>

                    <button
                        type="button"
                        onClick={() => setEditing((value) => !value)}
                        aria-expanded={editing}
                        className="text-xs font-medium text-brand-600 transition hover:text-brand-700 dark:text-brand-400"
                    >
                        {editing ? t('work.task.edit.cancel') : t('work.task.edit')}
                    </button>

                    {editing ? (
                        <EditTaskForm
                            task={task}
                            projectId={project.id}
                            priorities={priorities}
                            onDone={() => setEditing(false)}
                        />
                    ) : null}
                </div>
            ) : null}
        </article>
    );
}

function EditTaskForm({
    task,
    projectId,
    priorities,
    onDone,
}: {
    task: TaskCard;
    projectId: string;
    priorities: PriorityOption[];
    onDone: () => void;
}) {
    const { t } = useI18n();

    const { data, setData, patch, processing, errors } = useForm<{
        title: string;
        description: string;
        priority: string;
        due_date: string;
    }>({
        title: task.title,
        description: task.description ?? '',
        priority: task.priority,
        due_date: task.due_date ?? '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        patch(`/projects/${projectId}/tasks/${task.id}`, {
            preserveScroll: true,
            onSuccess: () => onDone(),
        });
    };

    return (
        <form
            onSubmit={submit}
            noValidate
            className="mt-2 space-y-2 rounded-md border border-slate-200 bg-slate-50 p-2 dark:border-slate-800 dark:bg-slate-900"
        >
            <div>
                <label htmlFor={`edit-title-${task.id}`} className="block text-xs font-medium">
                    {t('work.task.title')}
                </label>
                <input
                    id={`edit-title-${task.id}`}
                    type="text"
                    value={data.title}
                    onChange={(event) => setData('title', event.target.value)}
                    aria-invalid={errors.title ? true : undefined}
                    className="mt-1 block w-full rounded-md border border-slate-300 bg-white px-2 py-1 text-xs focus:border-brand-500 dark:border-slate-700 dark:bg-slate-900"
                />
                {errors.title ? (
                    <p role="alert" className="mt-1 text-xs text-rose-600 dark:text-rose-400">
                        {errors.title}
                    </p>
                ) : null}
            </div>

            <div>
                <label htmlFor={`edit-desc-${task.id}`} className="block text-xs font-medium">
                    {t('work.task.description')}
                </label>
                <input
                    id={`edit-desc-${task.id}`}
                    type="text"
                    value={data.description}
                    onChange={(event) => setData('description', event.target.value)}
                    aria-invalid={errors.description ? true : undefined}
                    className="mt-1 block w-full rounded-md border border-slate-300 bg-white px-2 py-1 text-xs focus:border-brand-500 dark:border-slate-700 dark:bg-slate-900"
                />
                {errors.description ? (
                    <p role="alert" className="mt-1 text-xs text-rose-600 dark:text-rose-400">
                        {errors.description}
                    </p>
                ) : null}
            </div>

            <div className="grid grid-cols-2 gap-2">
                <div>
                    <label
                        htmlFor={`edit-priority-${task.id}`}
                        className="block text-xs font-medium"
                    >
                        {t('work.task.priority')}
                    </label>
                    <select
                        id={`edit-priority-${task.id}`}
                        value={data.priority}
                        onChange={(event) => setData('priority', event.target.value)}
                        aria-invalid={errors.priority ? true : undefined}
                        className="mt-1 block w-full rounded-md border border-slate-300 bg-white px-2 py-1 text-xs focus:border-brand-500 dark:border-slate-700 dark:bg-slate-900"
                    >
                        {priorities.map((priority) => (
                            <option key={priority.value} value={priority.value}>
                                {priority.label}
                            </option>
                        ))}
                    </select>
                    {errors.priority ? (
                        <p role="alert" className="mt-1 text-xs text-rose-600 dark:text-rose-400">
                            {errors.priority}
                        </p>
                    ) : null}
                </div>
                <div>
                    <label htmlFor={`edit-due-${task.id}`} className="block text-xs font-medium">
                        {t('work.task.due_date')}
                    </label>
                    <input
                        id={`edit-due-${task.id}`}
                        type="date"
                        value={data.due_date}
                        onChange={(event) => setData('due_date', event.target.value)}
                        aria-invalid={errors.due_date ? true : undefined}
                        className="mt-1 block w-full rounded-md border border-slate-300 bg-white px-2 py-1 text-xs focus:border-brand-500 dark:border-slate-700 dark:bg-slate-900"
                    />
                    {errors.due_date ? (
                        <p role="alert" className="mt-1 text-xs text-rose-600 dark:text-rose-400">
                            {errors.due_date}
                        </p>
                    ) : null}
                </div>
            </div>

            <button
                type="submit"
                disabled={processing}
                className="inline-flex items-center justify-center rounded-md bg-brand-600 px-3 py-1 text-xs font-medium text-white transition hover:bg-brand-700 disabled:opacity-60"
            >
                {processing ? t('work.saving') : t('work.task.edit.submit')}
            </button>
        </form>
    );
}
