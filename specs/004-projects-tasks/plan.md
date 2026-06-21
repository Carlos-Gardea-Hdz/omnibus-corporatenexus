# Slice 004 — Projects & Tasks · PLAN (the HOW) + CONTRACT

> SDD step 2. Gated on CLAUDE.md § No-negociables (spec §7). The CONTRACT below
> is what implementation agents follow VERBATIM. CMS Content (Article CRUD +
> `ArticleStatus`) is the adapted reference; tenant context is the twist.

---

## Architecture summary

- New domain `app/Domain/Work/` (Models, Actions, Data, Enums, Exceptions).
- Two tenant tables: `projects`, `tasks` (FK `tasks.project_id → projects.id`,
  `tasks.assigned_to → users.id` nullable). All in `database/migrations/tenant`.
- Reuse tenant `App\Models\User` as assignee/member.
- Controllers `Tenant\ProjectController`, `Tenant\TaskController` in the tenant
  `auth` route group; project mutations gated to admin+; task mutations to any
  member.
- `Note` fully removed; landing + isolation/identification/pipeline tests +
  seeder migrated to projects/tasks.

## Files to touch / create

**Migrations (tenant):**
- CREATE `database/migrations/tenant/2026_06_21_000000_create_projects_table.php`
- CREATE `database/migrations/tenant/2026_06_21_000100_create_tasks_table.php`
- DELETE `database/migrations/tenant/2026_06_18_060000_create_notes_table.php`

**Domain:**
- CREATE `app/Domain/Work/Enums/{ProjectStatus,TaskStatus,TaskPriority}.php`
- CREATE `app/Domain/Work/Models/{Project,Task}.php`
- CREATE `app/Domain/Work/Exceptions/{InvalidProjectTransitionException,InvalidTaskTransitionException,ProjectArchivedException}.php`
- CREATE `app/Domain/Work/Data/{CreateProjectData,UpdateProjectData,TransitionProjectData,CreateTaskData,UpdateTaskData,TransitionTaskData,AssignTaskData}.php`
- CREATE `app/Domain/Work/Actions/{CreateProject,UpdateProject,TransitionProject,CreateTask,UpdateTask,TransitionTask,AssignTask}.php`

**Models / factories:**
- DELETE `app/Models/Note.php`, `database/factories/NoteFactory.php`
- CREATE `database/factories/ProjectFactory.php`, `database/factories/TaskFactory.php`

**HTTP:**
- CREATE `app/Http/Controllers/Tenant/{ProjectController,TaskController}.php`
- EDIT `routes/tenant.php` (add the project/task routes inside the `auth` group)
- EDIT `app/Http/Controllers/Tenant/LandingController.php` (drop notes_count →
  projects_count + open_tasks_count)

**Frontend:**
- CREATE `resources/js/Pages/Tenant/Projects/Index.tsx`,
  `resources/js/Pages/Tenant/Projects/Show.tsx`
- EDIT `resources/js/Pages/Tenant/Landing.tsx` (notes_count → summary)
- EDIT `resources/js/lib/i18n.tsx` (remove `landing.notes_count`; add work keys)

**Lang:** CREATE `lang/{es,en}/work.php`; EDIT `lang/{es,en}` landing keys.

**Seeder:** EDIT `database/seeders/TenantDatabaseSeeder.php`
(`seedDemoNotes` → `seedDemoWork`).

**Arch:** EDIT `tests/Unit/ArchTest.php` (Work domain guards; drop/redirect Note
guards to Work models).

**Tests migrated/created** — see CONTRACT §12.

---

# ───────────────── CONTRACT (follow verbatim) ─────────────────

## C1. Tenant migrations (reversible, `database/migrations/tenant`)

### `2026_06_21_000000_create_projects_table.php`
```
projects:
  id            uuid  primary  (UUIDv7 — Project::newUniqueId(), $keyType=string, $incrementing=false)
  name          string(120)  not null
  description   text  nullable
  status        string(20)  not null  default 'planning'
  created_by    uuid  nullable   (the member who created it; NO FK constraint — informational, a removed member must not cascade-delete projects; nullable + nullOnDelete-by-app)
  created_at / updated_at  timestamps
indexes: index(status)
```
- `created_by` is a plain `uuid` column WITHOUT a DB FK (users PK is `bigint id`,
  not uuid — do NOT FK to it; store the bigint as well? NO). **Correction:** the
  tenant `users` PK is `bigint id`. So `created_by` is `unsignedBigInteger`
  nullable, FK `->nullable()->constrained('users')->nullOnDelete()`. Keep it.
- **Decision (PK):** projects/tasks use **UUIDv7** string PKs (CONTRACT C3 reuses
  the existing `Uuid7Generator`). This differs from tenant `users` (bigint, by
  prior decision) — projects/tasks are new, so apply the program UUIDv7 law.
  `assigned_to`/`created_by` remain `unsignedBigInteger` because they FK to the
  bigint `users.id`.

Reconciled `projects`:
```
id            uuid string PK (UUIDv7), not incrementing
name          string(120) not null
description   text nullable
status        string(20) not null default 'planning'
created_by    unsignedBigInteger nullable, FK users.id nullOnDelete
created_at/updated_at
index(status)
```

### `2026_06_21_000100_create_tasks_table.php`
```
id            uuid string PK (UUIDv7), not incrementing
project_id    uuid not null, FK projects.id cascadeOnDelete
title         string(160) not null
description   text nullable
status        string(20) not null default 'todo'
priority      string(10) not null default 'medium'
assigned_to   unsignedBigInteger nullable, FK users.id nullOnDelete
due_date      date nullable
created_by    unsignedBigInteger nullable, FK users.id nullOnDelete
created_at/updated_at
indexes: index(project_id), index(status), index(assigned_to), index(['project_id','status'])
```
- `down()` drops `tasks` then `projects` (tasks first — FK order). Each migration
  drops its own table; ensure the tasks migration timestamp sorts AFTER projects
  so up() creates projects first, and tenants:migrate:rollback removes tasks
  first.
- No `tenant_id` column — isolation is by connection (matches notes/users).

## C2. Enums (`app/Domain/Work/Enums`, `#[TypeScript]`, backed)

### `ProjectStatus: string`
```
Planning='planning', Active='active', Completed='completed', Archived='archived'
allowedTransitions():
  Planning  => [Active, Archived]
  Active    => [Completed, Archived]
  Completed => [Active, Archived]
  Archived  => [Active]
canTransitionTo(self): in_array(strict)
isArchived(): bool  => $this === Archived   // task guard reads this
label(): __('work.project_status.'.value)
color(): Planning #64748B, Active #2563EB, Completed #16A34A, Archived #9CA3AF
```

### `TaskStatus: string`
```
Todo='todo', InProgress='in_progress', Done='done'
allowedTransitions():
  Todo       => [InProgress]
  InProgress => [Done, Todo]
  Done       => [InProgress]
canTransitionTo(self): in_array(strict)
label(): __('work.task_status.'.value)
color(): Todo #64748B, InProgress #2563EB, Done #16A34A
```

### `TaskPriority: string`
```
Low='low', Medium='medium', High='high', Urgent='urgent'
weight(): Low 1, Medium 2, High 3, Urgent 4   // for ordering
label(): __('work.task_priority.'.value)
color(): Low #64748B, Medium #2563EB, High #F59E0B, Urgent #DC2626
```

## C3. Models (`app/Domain/Work/Models`, `final`, `@property`, casts, newFactory)

### `Project`
```php
@property string $id
@property string $name
@property string|null $description
@property ProjectStatus $status
@property int|null $created_by
@property Carbon $created_at
@property Carbon $updated_at
@property-read Collection<int, Task> $tasks
```
- `use HasUuids;` with `newUniqueId(): string => Uuid7Generator`-equivalent.
  Reuse the existing UUIDv7 generation used by `Tenant` (`App\Domain\Tenancy\Models\Uuid7Generator`);
  expose `public function newUniqueId(): string` returning a UUIDv7. `$keyType='string'`,
  `$incrementing=false`.
- `$fillable = ['name','description','status','created_by']`.
- `casts(): ['status' => ProjectStatus::class]`.
- `tasks(): HasMany` → `Task::class`.
- `newFactory(): ProjectFactory`.

### `Task`
```php
@property string $id
@property string $project_id
@property string $title
@property string|null $description
@property TaskStatus $status
@property TaskPriority $priority
@property int|null $assigned_to
@property Carbon|null $due_date
@property int|null $created_by
@property Carbon $created_at
@property Carbon $updated_at
@property-read Project $project          // belongsTo, non-nullable (FK required) — NOT |null
@property-read User|null $assignee       // nullable belongsTo
```
- `HasUuids` (UUIDv7), `$keyType='string'`, `$incrementing=false`.
- `$fillable = ['project_id','title','description','status','priority','assigned_to','due_date','created_by']`.
- `casts(): ['status'=>TaskStatus::class,'priority'=>TaskPriority::class,'due_date'=>'date']`.
- `project(): BelongsTo` → `Project::class`.
- `assignee(): BelongsTo` → `User::class, 'assigned_to'`.
- `newFactory(): TaskFactory`.

## C4. DTOs (`app/Domain/Work/Data`, extend Spatie `Data`, `#[TypeScript]`, `final`)

> Validation = SSOT. `role`/status/priority via `Rule::enum`. Web failure → 302 +
> session errors (resolved by controller signature). NO FormRequest.

### `CreateProjectData`
```
name: string   #[Min(3), Max(120)]
description: ?string   #[Max(2000)]   (nullable)
```
(status NOT accepted on create — always defaults to `planning` in the Action.)

### `UpdateProjectData`
```
name: string   #[Min(3), Max(120)]
description: ?string   #[Max(2000)]
```

### `TransitionProjectData`
```
status: ProjectStatus
rules(): ['status' => [Rule::enum(ProjectStatus::class)]]
```

### `CreateTaskData`
```
title: string   #[Min(3), Max(160)]
description: ?string   #[Max(2000)]
priority: TaskPriority = TaskPriority::Medium
assigned_to: ?int           (nullable — unassigned allowed)
due_date: ?CarbonImmutable  #[Date]  (nullable)
rules():
  'priority'    => [Rule::enum(TaskPriority::class)]
  'assigned_to' => ['nullable', Rule::exists('users','id')]   // tenant-context exists = member guard
```
(status NOT accepted on create — always `todo`. project_id comes from the route,
not the DTO.)

### `UpdateTaskData`
```
title: string   #[Min(3), Max(160)]
description: ?string   #[Max(2000)]
priority: TaskPriority
due_date: ?CarbonImmutable  #[Date]
rules(): ['priority' => [Rule::enum(TaskPriority::class)]]
```
(status + assignee changed via the dedicated transition/assign endpoints, not here.)

### `TransitionTaskData`
```
status: TaskStatus
rules(): ['status' => [Rule::enum(TaskStatus::class)]]
```

### `AssignTaskData`
```
assigned_to: ?int   (null = unassign)
rules(): ['assigned_to' => ['nullable', Rule::exists('users','id')]]   // member guard
```

## C5. Exceptions (`app/Domain/Work/Exceptions`, `final`)
- `InvalidProjectTransitionException::between(ProjectStatus $from, ProjectStatus $to)`
- `InvalidTaskTransitionException::between(TaskStatus $from, TaskStatus $to)`
- `ProjectArchivedException::make()`  (raised when mutating tasks of an archived project)
- Each rendered as **302 + flash error** (NOT 500) via a handler registered in
  `bootstrap/app.php` (mirror the membership exception handlers — redirect back
  with `->with('error', __('work.errors.<key>'))`). Add the three to the existing
  `withExceptions` block.

## C6. Actions (`app/Domain/Work/Actions`, `final`, one op, `DB::transaction` for writes)

### `CreateProject`
`handle(CreateProjectData $data, User $actor): Project`
- `DB::transaction` → `Project::create([... status => ProjectStatus::Planning, created_by => $actor->id])`. Return it.

### `UpdateProject`
`handle(Project $project, UpdateProjectData $data): Project`
- `DB::transaction` → fill name/description, save, return.

### `TransitionProject`
`handle(Project $project, ProjectStatus $target): Project`
- `if (! $project->status->canTransitionTo($target)) throw InvalidProjectTransitionException::between(...)`.
- `DB::transaction` → set status, save, return. (Archive is the `→ Archived` edge.)

### `CreateTask`
`handle(Project $project, CreateTaskData $data, User $actor): Task`
- `if ($project->status->isArchived()) throw ProjectArchivedException::make()`.
- `DB::transaction` → `$project->tasks()->create([title, description, status => TaskStatus::Todo, priority => $data->priority, assigned_to => $data->assigned_to, due_date => $data->due_date, created_by => $actor->id])`. Return.
- (Belt-and-suspenders member guard: the DTO `exists('users','id')` already ran;
  no extra query needed since it's same-connection. The Action re-checks ONLY the
  archived guard, which the DTO cannot express.)

### `UpdateTask`
`handle(Task $task, UpdateTaskData $data): Task`
- `if ($task->project->status->isArchived()) throw ProjectArchivedException::make()`.
- `DB::transaction` → fill title/description/priority/due_date, save, return.

### `TransitionTask`
`handle(Task $task, TaskStatus $target): Task`
- `if ($task->project->status->isArchived()) throw ProjectArchivedException::make()`.
- `if (! $task->status->canTransitionTo($target)) throw InvalidTaskTransitionException::between(...)`.
- `DB::transaction` → set status, save, return.

### `AssignTask`
`handle(Task $task, AssignTaskData $data): Task`
- `if ($task->project->status->isArchived()) throw ProjectArchivedException::make()`.
- Member guard (defense in depth): `if ($data->assigned_to !== null && ! User::query()->whereKey($data->assigned_to)->exists()) throw a validation/illegal-assignee` — but since the DTO already enforced `exists('users','id')` in tenant context, this is redundant; KEEP the archived guard and set `assigned_to` (null = unassign). `DB::transaction` → save, return.

## C7. Controllers (`app/Http/Controllers/Tenant`, anemic ≤15 lines/method, `final`)

Pattern mirrors `MemberController`: `actor()` helper (`request()->user()` →
`abort_unless(... 403)`); a `assertCanManageProjects()` helper using
`$actor->role->canManageMembers()` (admin+) — REUSE that ladder (admin+ manages
both members AND projects; do NOT add a new role method, `canManageMembers` ==
"manages structural things"; OR add `MemberRole::canManageProjects(): bool =>
hasAtLeast(self::Admin)` for clarity — **DECISION: add `canManageProjects()`** as
an explicit alias so intent is legible and a future divergence is cheap).

### `ProjectController`
- `index(): Response` → list projects (any member). Props per C9.
- `show(Project $project): Response` → board (any member). Props per C9.
- `store(CreateProjectData $data, CreateProject $action): RedirectResponse` →
  `assertCanManageProjects`; `$action->handle($data, $this->actor())`; redirect
  `tenant.projects.index` + `work.project.created` flash.
- `update(Project $project, UpdateProjectData $data, UpdateProject $action): RedirectResponse`
  → assert; handle; redirect back + `work.project.updated`.
- `transition(Project $project, TransitionProjectData $data, TransitionProject $action): RedirectResponse`
  → assert; `$action->handle($project, $data->status)`; redirect back +
  `work.project.transitioned` (illegal edge → exception → 302 + flash).

### `TaskController` (any member — no project-manage gate; just `auth` + `actor()`)
- `store(Project $project, CreateTaskData $data, CreateTask $action): RedirectResponse`
  → `$action->handle($project, $data, $this->actor())`; redirect to
  `tenant.projects.show` + `work.task.created`.
- `update(Project $project, Task $task, UpdateTaskData $data, UpdateTask $action): RedirectResponse`
  → handle; redirect back + `work.task.updated`.
- `transition(Project $project, Task $task, TransitionTaskData $data, TransitionTask $action): RedirectResponse`
  → handle; redirect back + `work.task.transitioned`.
- `assign(Project $project, Task $task, AssignTaskData $data, AssignTask $action): RedirectResponse`
  → handle; redirect back + `work.task.assigned`.

**Route-model binding runs on the tenant connection** (matches `{user}`): a
cross-tenant project/task id 404s — free isolation at the binding layer. Use
nested binding `{project}/{task}` and (optionally) a `scopeBindings()` so a task
must belong to the bound project (404 otherwise).

## C8. Routes (`routes/tenant.php`, inside the existing `auth` group)
```php
Route::get('/projects', [ProjectController::class,'index'])->name('tenant.projects.index');
Route::post('/projects', [ProjectController::class,'store'])->name('tenant.projects.store');
Route::get('/projects/{project}', [ProjectController::class,'show'])->name('tenant.projects.show');
Route::patch('/projects/{project}', [ProjectController::class,'update'])->name('tenant.projects.update');
Route::patch('/projects/{project}/status', [ProjectController::class,'transition'])->name('tenant.projects.transition');

Route::post('/projects/{project}/tasks', [TaskController::class,'store'])->name('tenant.tasks.store');
Route::patch('/projects/{project}/tasks/{task}', [TaskController::class,'update'])->name('tenant.tasks.update');
Route::patch('/projects/{project}/tasks/{task}/status', [TaskController::class,'transition'])->name('tenant.tasks.transition');
Route::patch('/projects/{project}/tasks/{task}/assignee', [TaskController::class,'assign'])->name('tenant.tasks.assign');
```
`->scopeBindings()` on the nested task routes. No closures; every route named.

## C9. Inertia prop shapes (snake_case — EXACT, asserted by prop-contract tests)

### `Tenant/Projects/Index`
```
projects: Array<{
  id: string
  name: string
  description: string | null
  status: string            // ProjectStatus value
  status_label: string
  status_color: string
  task_count: number
  open_task_count: number   // status != done
  created_at: string        // ISO
}>
can: { manage_projects: boolean }
statuses: Array<{ value: string; label: string; color: string }>   // for the create/filter UI
```

### `Tenant/Projects/Show`
```
project: {
  id: string
  name: string
  description: string | null
  status: string
  status_label: string
  status_color: string
  is_archived: boolean
  available_transitions: Array<{ value: string; label: string }>   // legal next ProjectStatus
}
columns: Array<{                       // board, one per TaskStatus
  status: string
  label: string
  color: string
  tasks: Array<{
    id: string
    title: string
    description: string | null
    status: string
    priority: string
    priority_label: string
    priority_color: string
    assignee: { id: number; name: string } | null
    due_date: string | null            // ISO date or null
    available_transitions: Array<{ value: string; label: string }>
  }>
}>
members: Array<{ id: number; name: string }>   // assignable list (THIS tenant only)
priorities: Array<{ value: string; label: string; color: string }>
filters: { status: string | null; assignee: number | null }
can: { manage_projects: boolean }
```
- The board filters (`status`, `assignee`) are read from the query string in the
  controller and echoed back in `filters`; the column tasks reflect the active
  filter. `members` is `User::query()->orderBy('name')->get(['id','name'])` —
  the member guard's source of truth, tenant-scoped.

## C10. Lang keys (`lang/{es,en}/work.php` + landing edits + i18n.tsx)

`work.php` keys (BOTH es + en):
```
project_status.planning|active|completed|archived
task_status.todo|in_progress|done
task_priority.low|medium|high|urgent
project.created|updated|transitioned
task.created|updated|transitioned|assigned
errors.invalid_project_transition
errors.invalid_task_transition
errors.project_archived
nav/title/labels for the two pages (projects.title, projects.empty, board.empty,
  task.create, task.assignee, task.unassigned, task.due_date, etc.)
```
- Landing: REMOVE `landing.notes_count`; ADD `landing.projects_count`,
  `landing.open_tasks_count` in i18n.tsx + (if applicable) lang files.
- i18n.tsx mirrors every enum label + UI key. A resolution test asserts parity.

## C11. Note removal / repurpose plan (do ALL of these atomically)
1. DELETE `app/Models/Note.php`, `database/factories/NoteFactory.php`,
   `database/migrations/tenant/2026_06_18_060000_create_notes_table.php`.
2. `LandingController::index` → replace `'notes_count' => Note::count()` with
   `'projects_count' => Project::count()`,
   `'open_tasks_count' => Task::query()->where('status','!=',TaskStatus::Done->value)->count()`.
3. `Landing.tsx` → props `{ projects_count, open_tasks_count }`; two Stat cards
   (`landing.projects_count`, `landing.open_tasks_count`). Drop notes Stat + key.
4. `TenantDatabaseSeeder` → `seedDemoNotes()` becomes `seedDemoWork()`: create
   2–3 fictional projects, each with a handful of fictional tasks (varied status
   + priority, some assigned to the seeded owner). NO real PII.
5. `ProjectFactory` + `TaskFactory` (fictional data; Task factory has
   `forProject()`/state helpers, default unassigned).
6. ArchTest: replace the two `App\Models\Note` guards with `App\Domain\Work\Models\Project`
   AND `App\Domain\Work\Models\Task` for central controllers + platform domain +
   activation job (central ↛ tenant-Work). Add Work-domain arch guards
   (HTTP-agnostic, enums backed, actions/DTOs final).
7. **Migrate the four note-bearing tests** (C12) so the isolation/identification/
   pipeline proofs run against projects/tasks. The landing prop assertions flip
   from `notes_count` to `projects_count`/`open_tasks_count`.

## C12. Test list (EXACT — Pest; DB/`__()` tests in Feature, NEVER Unit)

**Unit (`tests/Unit/Work`):**
- `ProjectStatusTest` — every legal edge true; a representative set of illegal
  edges (incl. self→self) false; `isArchived`; labelKey/color present for all cases.
- `TaskStatusTest` — same shape for the todo/in_progress/done graph.
- `TaskPriorityTest` — weight ordering; label/color for all cases.
- DTO unit tests OPTIONAL (rules() shape) — but anything calling `__()` or
  `exists` must be Feature.

**Feature (`tests/Feature/Work`, real Postgres, tenant-provisioning harness
copied from the existing Tenancy tests):**
- `ProjectCrudTest` — owner/admin create (201/302 + row, status planning),
  member create → 403, list returns only this tenant's projects, invalid create
  → 302 + session errors (NOT 422), update by admin+, member update → 403.
- `ProjectTransitionTest` — legal edge succeeds; archive works; illegal edge
  (e.g. archived→completed) → 302 + flash error (NOT 500); a member transition
  → 403.
- `TaskCrudTest` — any member creates a task (status todo), invalid → 302 +
  errors, update by a member, create on an ARCHIVED project → graceful reject
  (ProjectArchivedException → 302 + flash).
- `TaskTransitionTest` — legal edge (todo→in_progress) updates; illegal
  (todo→done) → 302 + flash, never 500.
- `TaskAssignmentTest` — assign to a member of THIS tenant succeeds; assign to a
  user id absent from this tenant DB → 302 + field error (the member guard);
  unassign (→null) succeeds; reassign to another member succeeds.
- `WorkCrossTenantIsolationTest` — A creates a project + task; B sees zero
  (Eloquent + raw) and cannot read/update/delete A's project or task; A intact
  afterward; distinct `tenant_*` DBs. (Adapt `CrossTenantIsolationTest`'s harness;
  add a SECOND `it()` for the tasks table, mirroring the existing members-table
  block. KEEP/rename the existing Note `it()` blocks to projects/tasks.)
- `ProjectPropContractTest` — `Tenant/Projects/Index` + `Tenant/Projects/Show`
  exact snake_case prop shapes (C9); assignable `members` is tenant-scoped.
- `WorkLangResolutionTest` — every `work.*` `__()` key resolves in es AND en;
  every enum label key + i18n.tsx key present (parity).

**Migrate (edit existing — do NOT leave dangling Note refs):**
- `CrossTenantIsolationTest` — its note `it()` → projects/tasks (or fold into the
  new file and delete the note blocks). NO `App\Models\Note` import anywhere.
- `LandingIsolationTest` — note isolation → project isolation; landing assertion
  → `projects_count`.
- `TenantIdentificationTest` — `notes_count` assertion → `projects_count`
  (+ `open_tasks_count`); seed projects/tasks instead of notes.
- `ProvisioningPipelineTest` — `Schema::hasTable('notes')` → `'projects'` +
  `'tasks'`; seeded-count assertion reads `Project::count()`.

## C13. Verification (do NOT run pest/migrate — shared DB)
- MAY run: `composer analyse` (PHPStan/Larastan level 10), `php artisan typescript:transform`, `pnpm tsc`/build, Pint format.
- Generated enum `.d.ts` is types only.
- Arch suite expected green after C11.6 edits.

## DEFERRED (NOTE in PRs; do NOT build)
attachments · comments/activity · real-time board · Gantt/dependencies/subtasks ·
notifications on assign · due-date reminders · bulk ops · drag-persist · tags/labels.
