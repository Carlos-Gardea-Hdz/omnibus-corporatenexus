# Slice 004 — Tenant Projects & Tasks (the real tenant work domain)

> SDD step 1 — the WHAT and WHY. The HOW lives in `plan.md`. Implementation agents
> follow the CONTRACT (returned by the architect / appended to `plan.md`) verbatim.

**Status:** specified · **Branch:** `feature/saas-foundation` · **Date:** 2026-06-21
**Replaces:** the `Note` placeholder (slice 001) — see §9.

---

## 0. Context & why

Slices 001–003 stood up the multitenant skeleton: DB-per-tenant provisioning
(stancl v3, ADR-001), tenant auth + a 3-rung member roster (`MemberRole`
owner>admin>member), and a central platform-admin console that can suspend a
tenant (`EnsureTenantIsActive` → 503). The only tenant-side "feature" so far is
`Note` — a deliberate placeholder whose sole job was to PROVE DB isolation
(`CrossTenantIsolationTest`, `LandingIsolationTest`, `TenantIdentificationTest`,
`ProvisioningPipelineTest`).

This slice replaces that placeholder with a **real tenant work domain: Projects
and Tasks.** A tenant's members create projects, break them into tasks, assign
those tasks to each other, and move tasks through a status lifecycle. Everything
lives in the **tenant database** (invisible across tenants) and is gated behind
tenant auth.

This is the CorporateNexus equivalent of the CMS Content domain (Article CRUD +
`ArticleStatus` state machine), adapted to tenant context.

### Non-goals (deferred — NOTE explicitly, do not build)

- File attachments on tasks/projects.
- Comments / activity feed / mentions.
- Real-time (websocket/broadcast) board updates.
- Gantt charts, dependencies, subtasks, time tracking, due-date reminders.
- Email/notification on assignment.
- Cross-project task moves, bulk operations, drag-and-drop persistence.
- Task labels/tags beyond the single `priority` enum.

A `due_date` column is included (nullable, simple date) because it is cheap and
shapes the board, but no reminders/automation around it.

---

## 1. Scope

### Projects
- A member with the right role creates a project (name, description, status).
- Any member lists projects, views a single project (its task board).
- The right role edits a project (name, description) and archives it
  (lifecycle, §4).

### Tasks
- Tasks belong to a project.
- Each task: title, description, `TaskStatus` (todo/in_progress/done),
  `TaskPriority` (low/medium/high/urgent), optional `assignee` (a tenant
  member), optional `due_date`.
- Create / list / filter (by status, by assignee) / view / transition status /
  assign / reassign / unassign.
- The board (project show page) groups tasks by status into columns.

### Auth / role gating (decided here — §5)
- ALL routes require an authenticated tenant member.
- **Projects** are managed by **admin+ (owner/admin)**: create, update, archive.
  Members may VIEW projects and the board.
- **Tasks** may be created/edited/transitioned/assigned by **ANY member**
  (members do the work). Rationale: projects are structural (manager concern);
  tasks are operational (everyone's concern).
- An assignee MUST be a member of THIS tenant (validated in tenant context, §6).

---

## 2. Replace the Note placeholder (§9 has the exact plan)

`Note` was a lifecycle-demo placeholder. It is **removed cleanly**:
- The model, factory, migration, seeder block, and i18n key go away.
- `LandingController` stops reading `notes_count`; it reads a **projects/tasks
  summary** instead (`projects_count`, `open_tasks_count`).
- Every test that asserts on notes is migrated to the new tenant-owned tables
  (projects/tasks) so the isolation/identification proofs stay green.
- `TenantDatabaseSeeder` seeds fictional demo projects+tasks instead of notes.

The isolation guarantees the Note tests proved are PRESERVED — they now run
against `projects`/`tasks`, which is strictly stronger (real FKs, an assignee).

---

## 3. Domain placement

New domain: `app/Domain/Work/` (Models, Actions, Data, Enums, Exceptions).
- Tenant-side domain: its models live in the tenant DB; migrations under
  `database/migrations/tenant`.
- HTTP-agnostic (arch: `App\Domain` ↛ `Illuminate\Http`).
- Controllers under `app/Http/Controllers/Tenant/` run in tenant context.
- Reuses the tenant `App\Models\User` as the member/assignee.

---

## 4. Lifecycle / state machines (single source of truth = enums)

### `ProjectStatus` (planning → active → completed → archived)
- `planning → active, archived`
- `active → completed, archived`
- `completed → active (reopen), archived`
- `archived → active (unarchive)`
- self→self illegal; everything not listed illegal.
- An **archived** project is read-only for tasks (no new tasks; no task
  transitions/assignments) — enforced in the task Actions.

### `TaskStatus` (todo → in_progress → done)
- `todo → in_progress`
- `in_progress → done, todo`
- `done → in_progress (reopen)`
- self→self illegal; everything not listed illegal.

### `TaskPriority` (low/medium/high/urgent) — ordering only, no transitions.

Each enum carries `label()` (via `__()`), `color()` (hex), and the status enums
carry `allowedTransitions()` + `canTransitionTo()`. No magic strings anywhere.

---

## 5. Acceptance scenarios (When … Then)

### Projects — CRUD + role gating
1. **When** an owner/admin POSTs a valid project, **Then** it is created in the
   tenant DB (status defaults to `planning`), and they are redirected (302) to
   the project list with a success flash.
2. **When** a `member` POSTs a project create/update/archive, **Then** 403.
3. **When** any member GETs the project list, **Then** 200 with the projects of
   THIS tenant only.
4. **When** an owner/admin submits an invalid project (name too short/missing),
   **Then** 302 + session validation errors (NEVER 422).
5. **When** an owner/admin archives a project, **Then** its status → `archived`
   and it no longer accepts new tasks/transitions.
6. **When** an owner/admin attempts an illegal project transition (e.g.
   `archived → completed`), **Then** it is rejected gracefully (302 + flash
   error, never 500).

### Tasks — CRUD + transition + assignment
7. **When** any member POSTs a valid task to a non-archived project, **Then** it
   is created with status `todo` and redirected to the board.
8. **When** a member transitions a task along a legal edge
   (`todo → in_progress`), **Then** the status updates; **When** an illegal edge
   (`todo → done`), **Then** rejected gracefully (302 + flash, never 500).
9. **When** a member assigns a task to a user who IS a member of this tenant,
   **Then** the assignee is set; **When** to a user id NOT in this tenant DB,
   **Then** validation fails (302 + field error) — the assignee guard.
10. **When** a member unassigns a task (assignee → null), **Then** it succeeds.
11. **When** a member reassigns an already-assigned task to another member,
    **Then** the assignee changes.
12. **When** a member adds a task to an **archived** project, **Then** rejected
    gracefully (the archived-project guard).
13. **When** the board is filtered by status and/or assignee, **Then** only
    matching tasks return.

### Cross-tenant isolation (THE law)
14. **When** tenant A creates a project + task and tenant B queries
    projects/tasks (Eloquent AND raw), **Then** B sees zero rows and cannot
    read/update/delete A's project or task; A's data is intact afterward; the
    two tenants resolve to distinct physical `tenant_*` databases.

### Note replacement
15. **When** the tenant landing renders, **Then** it shows `projects_count` and
    `open_tasks_count` (not `notes_count`), and the page still returns 200 with
    the `Tenant/Landing` component.
16. **When** a tenant DB is provisioned + seeded, **Then** `projects`/`tasks`
    tables exist and hold fictional demo rows (> 0).

### Inertia prop contracts
17. Each page (`Tenant/Projects/Index`, `Tenant/Projects/Show`) exposes a
    stable snake_case prop shape, asserted by a Pest prop-contract test.

### i18n
18. Every `__()` key used server-side exists in BOTH `lang/es` and `lang/en`,
    and every enum/UI key resolves in the i18n map — asserted by a resolution
    test.

---

## 6. The assignee-is-a-member guard (security-critical)

A task's `assigned_to` may only reference a `users.id` that exists **in this
tenant's DB**. Because the connection is already swapped to the tenant DB when
validation runs, `Rule::exists('users', 'id')` resolves against the TENANT
`users` table — a cross-tenant id is physically absent and rejected. This is
both a DTO rule (graceful 302 + field error) AND re-asserted in the Action
(defense in depth). A task can never be assigned to a non-member.

---

## 7. Constitution gates (§ No-negociables)

- `declare(strict_types=1)`, `final`, `readonly` VO/DTO, backed enums.
- Spatie Data DTOs (validation SSOT + TS types); web failure = 302 + session
  errors, NEVER 422 (resolved via controller signature).
- Anemic controllers (≤15 lines/method, `final`); Actions = one op,
  `DB::transaction` for multi-row.
- Domain ↛ `Illuminate\Http`. Arch green (central ↛ tenant/Work models).
- Reversible tenant migrations. Inertia props snake_case. Generated enum
  `.d.ts` is types only. No real PII (fictional demo data).
- Tests touching DB/`__()` live in Feature, never Unit.
- Do NOT run pest/migrate after generating (shared DB); phpstan + tsc OK.

---

## 8. Out of scope / deferred (restated for clarity)

Attachments, comments, real-time, Gantt, notifications, subtasks, time tracking,
bulk ops, drag-persist, labels. Noted in the contract as `DEFERRED`.
