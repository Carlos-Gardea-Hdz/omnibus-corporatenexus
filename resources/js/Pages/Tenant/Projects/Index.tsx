import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { FlashMessages } from '@/Components/FlashMessages';
import { TenantNav } from '@/Components/TenantNav';
import { ColorBadge } from '@/Components/Work/ColorBadge';
import { AppLayout } from '@/Layouts/AppLayout';
import { useI18n } from '@/lib/i18n';

/**
 * Tenant project list. Every project row lives in the TENANT database, so the
 * list is intrinsically isolated to this organization. Creating a project is a
 * structural action gated to admin+ (`can.manage_projects`, server-computed) —
 * a plain `member` sees the read-only list with no create form. All mutations
 * are server-authoritative Inertia visits (302 + flash/session errors, never
 * 422); validation errors arrive in `errors` from the Spatie Data DTO.
 */

type ProjectRow = {
    id: string;
    name: string;
    description: string | null;
    status: string;
    status_label: string;
    status_color: string;
    task_count: number;
    open_task_count: number;
    created_at: string;
};

type StatusOption = {
    value: string;
    label: string;
    color: string;
};

type IndexProps = {
    projects: ProjectRow[];
    can: { manage_projects: boolean };
    statuses: StatusOption[];
};

export default function Index({ projects, can, statuses }: IndexProps) {
    const { t } = useI18n();

    return (
        <AppLayout>
            <Head title={t('work.projects.title')} />

            <TenantNav current="projects" />
            <FlashMessages />

            <section>
                <header className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-bold">{t('work.projects.title')}</h1>
                        <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
                            {t('work.projects.subtitle')}
                        </p>
                    </div>
                    <p className="text-sm font-medium">
                        {t('work.projects.total')}: {projects.length}
                    </p>
                </header>

                {can.manage_projects ? <CreateProjectForm statuses={statuses} /> : null}

                <ProjectList projects={projects} />
            </section>
        </AppLayout>
    );
}

function CreateProjectForm({ statuses }: { statuses: StatusOption[] }) {
    const { t } = useI18n();

    const { data, setData, post, processing, errors, reset } = useForm<{
        name: string;
        description: string;
    }>({
        name: '',
        description: '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post('/projects', {
            preserveScroll: true,
            onSuccess: () => reset(),
        });
    };

    // New projects always start in `planning` (the Action defaults it); surface
    // that to the creator so the lifecycle is legible from the first screen.
    const planning = statuses.find((s) => s.value === 'planning');

    return (
        <form
            onSubmit={submit}
            noValidate
            className="mt-8 rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900"
        >
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="text-sm font-semibold">{t('work.project.create')}</h2>
                {planning ? (
                    <span className="inline-flex items-center gap-1 text-xs text-slate-500 dark:text-slate-400">
                        {t('work.project.starts_as')}
                        <ColorBadge label={planning.label} color={planning.color} />
                    </span>
                ) : null}
            </div>

            <div className="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <label htmlFor="project-name" className="block text-sm font-medium">
                        {t('work.project.name')}
                    </label>
                    <input
                        id="project-name"
                        name="name"
                        type="text"
                        value={data.name}
                        onChange={(event) => setData('name', event.target.value)}
                        aria-invalid={errors.name ? true : undefined}
                        aria-describedby={errors.name ? 'project-name-error' : undefined}
                        className="mt-1 block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-500 dark:border-slate-700 dark:bg-slate-900"
                    />
                    {errors.name ? (
                        <p
                            id="project-name-error"
                            role="alert"
                            className="mt-1 text-sm text-rose-600 dark:text-rose-400"
                        >
                            {errors.name}
                        </p>
                    ) : null}
                </div>

                <div>
                    <label htmlFor="project-description" className="block text-sm font-medium">
                        {t('work.project.description')}
                    </label>
                    <input
                        id="project-description"
                        name="description"
                        type="text"
                        value={data.description}
                        onChange={(event) => setData('description', event.target.value)}
                        aria-invalid={errors.description ? true : undefined}
                        aria-describedby={
                            errors.description ? 'project-description-error' : undefined
                        }
                        className="mt-1 block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-500 dark:border-slate-700 dark:bg-slate-900"
                    />
                    {errors.description ? (
                        <p
                            id="project-description-error"
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
                    {processing ? t('work.project.creating') : t('work.project.create.submit')}
                </button>
            </div>
        </form>
    );
}

function ProjectList({ projects }: { projects: ProjectRow[] }) {
    const { t } = useI18n();

    if (projects.length === 0) {
        return (
            <p className="mt-8 rounded-xl border border-dashed border-slate-300 px-4 py-10 text-center text-sm text-slate-500 dark:border-slate-700 dark:text-slate-400">
                {t('work.projects.empty')}
            </p>
        );
    }

    return (
        <ul className="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {projects.map((project) => (
                <li key={project.id}>
                    <Link
                        href={`/projects/${project.id}`}
                        className="block h-full rounded-xl border border-slate-200 bg-white p-5 transition hover:border-brand-400 hover:shadow-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 dark:border-slate-800 dark:bg-slate-900 dark:hover:border-brand-600"
                    >
                        <div className="flex items-start justify-between gap-3">
                            <h3 className="font-semibold">{project.name}</h3>
                            <ColorBadge label={project.status_label} color={project.status_color} />
                        </div>
                        {project.description ? (
                            <p className="mt-2 line-clamp-2 text-sm text-slate-500 dark:text-slate-400">
                                {project.description}
                            </p>
                        ) : null}
                        <dl className="mt-4 flex items-center gap-4 text-xs text-slate-500 dark:text-slate-400">
                            <div>
                                <dt className="inline">{t('work.projects.tasks')}: </dt>
                                <dd className="inline font-semibold text-slate-700 dark:text-slate-200">
                                    {project.task_count}
                                </dd>
                            </div>
                            <div>
                                <dt className="inline">{t('work.projects.open_tasks')}: </dt>
                                <dd className="inline font-semibold text-slate-700 dark:text-slate-200">
                                    {project.open_task_count}
                                </dd>
                            </div>
                        </dl>
                    </Link>
                </li>
            ))}
        </ul>
    );
}
