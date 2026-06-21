<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Domain\Work\Actions\CreateProject;
use App\Domain\Work\Actions\TransitionProject;
use App\Domain\Work\Actions\UpdateProject;
use App\Domain\Work\Data\CreateProjectData;
use App\Domain\Work\Data\TransitionProjectData;
use App\Domain\Work\Data\UpdateProjectData;
use App\Domain\Work\Enums\ProjectStatus;
use App\Domain\Work\Enums\TaskPriority;
use App\Domain\Work\Enums\TaskStatus;
use App\Domain\Work\Models\Project;
use App\Domain\Work\Models\Task;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Project administration inside tenant context (slice-004, CONTRACT §C7). Gated
 * `auth` upstream in routes/tenant.php and inheriting EnsureTenantIsActive, so a
 * suspended tenant 503s before any handler runs. index/show are open to any
 * member; store/update/transition add the admin+ gate (`assertCanManageProjects`
 * → 403 for a `member`). Anemic by law (≤15 lines/method): each mutation hands a
 * validated DTO (resolved via the method signature → web failure is 302 + session
 * errors, never 422) plus the acting {@see User} to its Action, which owns the
 * state-machine and project-archived guards (each surfaced as a 302 + flash by the
 * handlers in bootstrap/app.php, never a 500). No `Request`, no `DB` facade here.
 * {project} binds on the tenant connection, so a cross-tenant id 404s.
 */
final class ProjectController extends Controller
{
    public function index(): Response
    {
        $actor = $this->actor();

        $projects = Project::query()
            ->withCount([
                'tasks',
                'tasks as open_task_count' => fn ($query) => $query->where('status', '!=', TaskStatus::Done->value),
            ])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Project $project): array => $this->mapProjectRow($project))
            ->all();

        return Inertia::render('Tenant/Projects/Index', [
            'projects' => $projects,
            'can' => ['manage_projects' => $actor->role->canManageMembers()],
            'statuses' => $this->statusOptions(),
        ]);
    }

    public function show(Project $project): Response
    {
        $actor = $this->actor();
        $filters = $this->boardFilters();

        return Inertia::render('Tenant/Projects/Show', [
            'project' => $this->mapProject($project),
            'columns' => $this->board($project, $filters),
            'members' => User::query()->orderBy('name')->get(['id', 'name']),
            'priorities' => $this->priorityOptions(),
            'filters' => $filters,
            'can' => ['manage_projects' => $actor->role->canManageMembers()],
        ]);
    }

    public function store(CreateProjectData $data, CreateProject $action): RedirectResponse
    {
        $this->assertCanManageProjects();
        $action->handle($data, $this->actor());

        return redirect()->route('tenant.projects.index')->with('success', __('work.project.created'));
    }

    public function update(Project $project, UpdateProjectData $data, UpdateProject $action): RedirectResponse
    {
        $this->assertCanManageProjects();
        $action->handle($project, $data);

        return redirect()->route('tenant.projects.show', $project)->with('success', __('work.project.updated'));
    }

    public function transition(Project $project, TransitionProjectData $data, TransitionProject $action): RedirectResponse
    {
        $this->assertCanManageProjects();
        $action->handle($project, $data->status);

        return redirect()->route('tenant.projects.show', $project)->with('success', __('work.project.transitioned'));
    }

    /** The authenticated acting member (the `auth` gate guarantees presence). */
    private function actor(): User
    {
        $actor = request()->user();
        abort_unless($actor instanceof User, HttpResponse::HTTP_FORBIDDEN);

        return $actor;
    }

    /** A `member` cannot manage projects → 403 (gates store/update/transition; admin+ only). */
    private function assertCanManageProjects(): void
    {
        abort_unless($this->actor()->role->canManageMembers(), HttpResponse::HTTP_FORBIDDEN);
    }

    /**
     * The active board filters, read ONLY from the query string (never persisted).
     *
     * @return array{status: string|null, assignee: int|null}
     */
    private function boardFilters(): array
    {
        $status = request()->query('status');
        $assignee = request()->query('assignee');

        return [
            'status' => TaskStatus::tryFrom(is_string($status) ? $status : '')?->value,
            'assignee' => is_numeric($assignee) ? (int) $assignee : null,
        ];
    }

    /**
     * Shape one project row for the index list (snake_case contract).
     *
     * @return array<string, mixed>
     */
    private function mapProjectRow(Project $project): array
    {
        return [
            'id' => $project->id,
            'name' => $project->name,
            'description' => $project->description,
            'status' => $project->status->value,
            'status_label' => $project->status->label(),
            'status_color' => $project->status->color(),
            'task_count' => (int) ($project->tasks_count ?? 0),
            'open_task_count' => (int) ($project->open_task_count ?? 0),
            'created_at' => $project->created_at->toIso8601String(),
        ];
    }

    /**
     * Shape the project header for the board (snake_case contract).
     *
     * @return array<string, mixed>
     */
    private function mapProject(Project $project): array
    {
        return [
            'id' => $project->id,
            'name' => $project->name,
            'description' => $project->description,
            'status' => $project->status->value,
            'status_label' => $project->status->label(),
            'status_color' => $project->status->color(),
            'is_archived' => $project->status->isArchived(),
            'available_transitions' => $this->transitionOptions($project->status->allowedTransitions()),
        ];
    }

    /**
     * The board columns — one per TaskStatus — honoring the active filters.
     *
     * @param  array{status: string|null, assignee: int|null}  $filters
     * @return list<array<string, mixed>>
     */
    private function board(Project $project, array $filters): array
    {
        $tasks = $project->tasks()
            ->with('assignee:id,name')
            ->when($filters['assignee'] !== null, fn ($query) => $query->where('assigned_to', $filters['assignee']))
            ->orderByDesc('created_at')
            ->get();

        return array_map(function (TaskStatus $status) use ($tasks, $filters): array {
            $matchesFilter = $filters['status'] === null || $filters['status'] === $status->value;

            return [
                'status' => $status->value,
                'label' => $status->label(),
                'color' => $status->color(),
                'tasks' => $matchesFilter
                    ? $tasks->where('status', $status)->map(fn (Task $task): array => $this->mapTask($task))->values()->all()
                    : [],
            ];
        }, TaskStatus::cases());
    }

    /**
     * Shape one task card for the board (snake_case contract).
     *
     * @return array<string, mixed>
     */
    private function mapTask(Task $task): array
    {
        return [
            'id' => $task->id,
            'title' => $task->title,
            'description' => $task->description,
            'status' => $task->status->value,
            'priority' => $task->priority->value,
            'priority_label' => $task->priority->label(),
            'priority_color' => $task->priority->color(),
            'assignee' => $task->assignee === null
                ? null
                : ['id' => $task->assignee->id, 'name' => $task->assignee->name],
            'due_date' => $task->due_date?->toDateString(),
            'available_transitions' => $this->transitionOptions($task->status->allowedTransitions()),
        ];
    }

    /**
     * Map a set of enum cases to {value,label} options.
     *
     * @param  array<int, ProjectStatus|TaskStatus>  $cases
     * @return list<array{value: string, label: string}>
     */
    private function transitionOptions(array $cases): array
    {
        return array_map(
            fn (ProjectStatus|TaskStatus $case): array => ['value' => $case->value, 'label' => $case->label()],
            array_values($cases),
        );
    }

    /** @return list<array{value: string, label: string, color: string}> */
    private function statusOptions(): array
    {
        return array_map(
            fn (ProjectStatus $status): array => [
                'value' => $status->value,
                'label' => $status->label(),
                'color' => $status->color(),
            ],
            ProjectStatus::cases(),
        );
    }

    /** @return list<array{value: string, label: string, color: string}> */
    private function priorityOptions(): array
    {
        return array_map(
            fn (TaskPriority $priority): array => [
                'value' => $priority->value,
                'label' => $priority->label(),
                'color' => $priority->color(),
            ],
            TaskPriority::cases(),
        );
    }
}
