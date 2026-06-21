# Spec 003 — Central Platform Admin (the SaaS operator console)

- **Status:** Draft (architect contract)
- **Domain:** `app/Domain/Platform` (new, CENTRAL-context) + reuse `app/Domain/Tenancy`
- **Engine:** stancl/tenancy v3 (ADR-001), DB-per-tenant on PostgreSQL 18
- **Author:** Architect agent — 2026-06-20
- **Grounding:** Biblia §4.5 (the operator side: who manages tenants — suspend,
  plan, billing — the central admin). ADR-001. Slice 001
  (`specs/001-tenant-lifecycle/spec.md`: `CreateTenant`, `TenantStatus` graph, the
  queued provisioning pipeline, `InitializeTenancyByDomain`,
  `PreventAccessFromCentralDomains`, `config/tenancy.php`). Slice 002
  (`specs/002-tenant-auth-teams/spec.md`: the tenant-side auth that this slice is the
  CENTRAL counterpart of — and which explicitly DEFERRED central platform-admin auth
  to "a separate central guard against a central `platform_admins` table", §3). The
  CMS Identity slice (`/home/carlos/coding/omnibus-cms/app/Domain/Identity`,
  `app/Http/Controllers/Auth/LoginController`) as the auth / Action / DTO /
  generic-non-enumerating-login / role-gate reference, **adapted to the CENTRAL
  operator console** (the operator is the platform owner, not a tenant user).

## 1. WHAT & WHY

Slices 001–002 delivered the **tenant** side: provisioning a tenant, the per-tenant
DB, tenant-user auth and member/role/seat-limit management **inside** each tenant DB.
Nobody yet operates the platform. This slice builds the **CENTRAL operator console**
— the SaaS operator's private back office — entirely on the **central** connection
(the tenants registry), never entering tenant context.

1. **Platform-admin auth (separate identity).** A central `platform_admins` table +
   model + login / logout on the **central** domain, behind a dedicated `admin`
   guard. A platform admin authenticates against the **central** DB (default
   connection, NO tenant initialization). This identity is **disjoint** from tenant
   users: a tenant user can NEVER authenticate as a platform admin, and a platform
   admin is not a tenant user. Generic, non-enumerating `auth.failed`; **302 not
   422**; session regenerate on login / invalidate on logout. The first platform
   admin is seeded by a console command (or seeder).
2. **Platform dashboard.** List **all** tenants from the central registry (name,
   subdomain, status, plan, owner_email, created_at) with status + plan filters and
   per-status counts. A tenant **detail** view.
3. **Tenant management (central Actions).** `SuspendTenant` (Active→Suspended),
   `ReactivateTenant` (Suspended→Active), `ChangeTenantPlan` (updates the plan →
   recomputes the Pennant feature gates; the seat limit is read live from the new
   plan). Illegal transitions are guarded by the existing `TenantStatus` graph and
   surface as **302 + error, never 500**.
4. **A suspended tenant is blocked from serving its app.** A status check in the
   tenant identification path (an `EnsureTenantIsActive` middleware in every tenant
   route group, modeled on stancl's own `CheckTenantForMaintenanceMode`) makes a
   suspended tenant's subdomain return **503** (maintenance), so suspension has real
   teeth — it is not merely a label.

**Why this shape:** it proves the platform-operator privilege boundary (a central
identity disjoint from every tenant), and makes the `TenantStatus` lifecycle
**enforceable end-to-end** (suspend on the central registry → the tenant subdomain
actually stops serving). It is the smallest surface that turns the registry into a
real operator console.

## 2. IN SCOPE

- **Central migration(s)** (in `database/migrations`, the CENTRAL set — NOT
  `database/migrations/tenant`), reversible:
  - `platform_admins` table (UUIDv7 PK, `name`, unique `email`, `password`,
    `remember_token`, timestamps).
  - `TenantStatus::Suspended` already exists in the enum (confirmed); **no schema
    change** is needed for it (status is a custom column already). The spec only
    confirms the case + transition graph (§6) — no migration for it.
- **Platform domain (`app/Domain/Platform`):**
  - `PlatformAdmin` Eloquent model (central connection, `Authenticatable`,
    UUIDv7 id, `@property` PHPDoc, `newFactory()`, `password` hashed cast).
  - `PlatformLoginData` DTO (Spatie Data, `email` + `password` + `remember`).
  - `AuthenticatePlatformAdmin` Action (`admin` guard `Auth::attempt`, generic
    non-enumerating `ValidationException` on `email`).
  - `SuspendTenant`, `ReactivateTenant` Actions (TenantStatus-guarded, central tx).
  - `ChangeTenantPlan` Action (central tx; updates plan; the grandfathering decision
    in §5 S-PLAN is enforced here).
  - Output DTOs: `TenantSummaryData` (list row), `TenantDetailData` (detail page),
    `PlatformAdminData` (the authenticated admin for the shell).
- **Central HTTP (`app/Http/Controllers/Central`):**
  - `Auth/PlatformLoginController` (create/store/destroy) on the central domain.
  - `PlatformDashboardController` (index = tenant list + filters + counts).
  - `PlatformTenantController` (show = tenant detail; suspend / reactivate /
    change-plan write endpoints).
  - All gated by the `admin` guard; the console routes live in a `routes/central.php`
    group behind `auth:admin`. The public registration / provisioning routes
    (slice 001) stay **unauthenticated**.
- **Suspended-tenant block:** `EnsureTenantIsActive` middleware appended to the
  tenant route group in `routes/tenant.php` (after `InitializeTenancyByDomain` +
  `PreventAccessFromCentralDomains`); a non-Active tenant → **503**.
- **Seeder/command:** `php artisan platform:create-admin` (a console command that
  prompts/accepts name+email+password) and/or a `PlatformAdminSeeder` invoked from the
  central `DatabaseSeeder` to mint the first admin from `.env` (fictional creds only).
- **Bilingual lang keys** (ES/EN) for every new user-facing string in both PHP lang
  files (`lang/{es,en}/platform.php`) AND the React i18n dictionary, with a
  resolution test.
- The exact Pest test list in §9.

## 3. DEFERRED (note, do not build)

- **Stripe / billing** — `priceCents()` already exists on `TenantPlan`; a real
  billing integration (Stripe customers, subscriptions, invoices, dunning on
  suspend) is a later slice. `ChangeTenantPlan` updates the plan + features ONLY; it
  does NOT touch any payment provider. Note only.
- **Horizon / Pulse / Telescope dashboards** — gating ops dashboards behind the
  `admin` guard is a later slice; this slice ships the guard + console, not the
  dashboards. Note only.
- **Audit log** — every suspend/reactivate/plan-change is a privileged operator
  action that SHOULD be audited (who/when/before→after). This slice does NOT persist
  an audit trail; a `platform_audit_log` table + recording is a later slice. The
  Actions are written so an audit hook drops in cleanly (single operation, central
  tx). Note only.
- **Tenant deletion / archival from the console** — `TenantStatus::Archived` exists
  and `TenantDeleted → DeleteDatabase` is wired (slice 001), but a console
  "delete/archive tenant" control (irreversible, drops the tenant DB) is **deferred**
  (destructive; needs a confirm + audit). Note only.
- **Platform-admin roles / RBAC** — this slice ships a single flat platform-admin
  identity (every platform admin can do everything). A super-admin vs read-only
  operator split is a later slice.
- **MFA / passwordless for platform admins** — security §8 hardening; later slice.
- **Self-service platform-admin signup / password reset** — admins are minted by the
  console command/seeder only; no public signup, no reset flow this slice.
- **Live seat reconciliation on downgrade** — see §5 S-PLAN: existing over-limit
  members are **grandfathered** (kept, flagged on the detail view); no forced
  removal, no blocking of the tenant. Forced reconciliation is deferred.
- **Notifying the tenant owner on suspend/plan-change** — no email this slice.

## 4. ACTORS

- **Platform admin (operator):** authenticates on the central domain against
  `platform_admins`; sees every tenant; may suspend / reactivate / change plan. The
  only actor with access to the console.
- **Visitor (central, unauthenticated):** may reach registration + provisioning
  status (slice 001) only; hitting any console route → redirected to platform login.
- **Tenant user (slice 002):** has NO path to the console — their credentials do not
  exist in `platform_admins`; the `admin` guard authenticates against the central
  connection, which tenant users are physically absent from.

## 5. ACCEPTANCE SCENARIOS (When … Then)

**S1 — Platform-admin login happy path.**
When a seeded platform admin POSTs valid `{email, password}` to the platform login
route on the **central** domain, Then the `admin` guard authenticates against the
**central** `platform_admins` table, the session is regenerated, and the response is
a **302** to the platform dashboard.

**S2 — Login rejects bad credentials (no 422, no enumeration).**
When the email or password is wrong, Then the response is **302 + a session error on
`email`** (a single generic credential message), never 422, and the request stays
unauthenticated.

**S3 — Console is gated to platform admins.**
When an unauthenticated request hits any console route (dashboard, tenant detail,
suspend/reactivate/change-plan), Then the response is a **302** to the platform login
route; an authenticated platform admin gets **200** (reads) / **302** (writes).

**S4 — A tenant user CANNOT become a platform admin (the crown).**
Given a user that exists ONLY in a tenant DB (slice 002) with a known password, When
those exact `{email, password}` are submitted on the **central** platform-login
route, Then authentication **fails** (the `admin` guard queries
`platform_admins` on the **central** connection, which has no such row) — the tenant
user never reaches the console. Conversely, a platform admin's credentials do NOT
authenticate the tenant `web` guard.

**S5 — Dashboard lists every tenant from the central registry.**
When a platform admin opens the dashboard, Then they receive a paginated list of ALL
tenants with `name, subdomain, status, plan, owner_email, created_at`, plus a
per-status count map and the available filter options — read entirely from the
central `tenants` (+ `domains`) tables, with **no** tenant context entered and **no**
per-tenant DB queried.

**S6 — Dashboard filters by status and plan.**
When a platform admin filters by `status=suspended` (and/or `plan=team`), Then only
matching tenants are returned and the counts reflect the unfiltered totals (the
filter narrows the list, not the count badges).

**S7 — Tenant detail view.**
When a platform admin opens a tenant's detail, Then they receive the tenant's full
registry record (`id, name, subdomain, status, plan, owner_email, created_at`), the
plan's `seat_limit` + `price_cents`, the resolved feature flags for the current plan,
and the allowed next status transitions + assignable plans (so the UI only offers
legal actions) — central-only.

**S8 — Suspend a tenant (Active→Suspended).**
When a platform admin suspends an **Active** tenant, Then `SuspendTenant` flips the
central `status` to `Suspended` (the transition is allowed by the graph) inside a
central transaction, and the response is **302 + success flash**. The registry row is
the only thing mutated; no tenant context is entered.

**S9 — A suspended tenant is BLOCKED from serving (falsifiable).**
Given a provisioned tenant whose central status is `Suspended`, When any request hits
that tenant's subdomain (e.g. its landing or login), Then `EnsureTenantIsActive`
(after identification) returns **503** (maintenance) — the tenant app does NOT serve.
Given the same tenant **reactivated** (status `Active`), Then the same request serves
normally (**200**). This is asserted on **real PostgreSQL** with an actually
provisioned tenant DB.

**S10 — Reactivate a tenant (Suspended→Active).**
When a platform admin reactivates a **Suspended** tenant, Then `ReactivateTenant`
flips the central status to `Active` (allowed by the graph), **302 + success flash**,
and S9's serve-again property holds.

**S11 — Illegal transition is rejected gracefully (never 500).**
When a platform admin attempts an illegal transition (e.g. suspend a `Pending` or
`Failed` tenant, or reactivate an `Active` one — anything `canTransitionTo()` denies),
Then the Action throws a domain `TenantTransitionException`, surfaced as **302 + a
session/flash error**, the status is **unchanged**, and the response is never a 500.

**S12 — Change plan recomputes the seat limit / features.**
When a platform admin changes a tenant's plan (e.g. Team→Business), Then
`ChangeTenantPlan` updates the central `plan` column inside a central transaction;
the seat limit is now `newPlan->seatLimit()` (read live — no stored copy to drift)
and the Pennant feature gates resolve against the new plan. **302 + success flash**.

**S13 — Downgrade grandfathers over-limit members (decided).**
Given a tenant currently holding M members on a plan, When a platform admin downgrades
to a plan whose `seatLimit() = N` with `0 < N < M`, Then the change **succeeds**
(no forced removal, no block); existing members are **grandfathered** — the tenant's
detail view flags it as **over seat limit** (`over_seat_limit: true`,
`seats_over: M - N`) and slice 002's invite path (which checks the live limit) already
**blocks new invites** until the tenant is back under the limit. (An Enterprise/0
target is unlimited → never over limit.)

**S14 — Central admin code never touches per-tenant models.**
The platform Actions, controllers and DTOs operate ONLY on the central `Tenant`
registry model; an arch test proves `App\Domain\Platform` and
`App\Http\Controllers\Central` never import `App\Models\User` or `App\Models\Note`
(the per-tenant models). Tenant suspension flips a central column; it never opens a
tenant DB.

**S15 — Prop contracts.**
`Auth/PlatformLogin`, `Platform/Dashboard`, and `Platform/Tenants/Show` each receive
exactly the snake_case prop shape in §7 (a contract test per page), with **no**
`password` / `remember_token` leak on any admin or tenant prop.

**S16 — Lang resolution.**
Every new `platform.*` key resolves in both `es` and `en`; every new React i18n key
exists for both locales.

## 6. TENANTSTATUS — CONFIRMED CASES + TRANSITION GRAPH

The existing enum (`app/Domain/Tenancy/Enums/TenantStatus.php`) is **authoritative and
already complete** for this slice — do NOT add or rename cases:

- Cases: `Pending`, `Active`, `Failed`, `Suspended`, `Archived`.
- `isServable()` → true only for `Active` (drives `EnsureTenantIsActive`).
- `canTransitionTo()` graph (default-deny):
  - `Pending  → {Active, Failed, Archived}`
  - `Active   → {Suspended, Archived}`
  - `Failed   → {Pending, Archived}`
  - `Suspended→ {Active, Archived}`
  - `Archived → {}` (terminal)

This slice exercises exactly `Active→Suspended` (suspend) and `Suspended→Active`
(reactivate). Every other operator transition is out of scope (Archived/delete is
DEFERRED, §3). The Actions MUST call `canTransitionTo()` and throw on a denied
transition — they must not hardcode the from/to pair.

## 7. INERTIA PROP CONTRACTS (snake_case)

### 7.1 `Auth/PlatformLogin` (GET central `/admin/login`)
```
{}   // no props needed (no tenant branding on the central operator login)
```
(Status/CSRF handled by the shared Inertia layer; no secrets.)

### 7.2 `Platform/Dashboard` (GET central `/admin`, auth:admin)
```
{
  admin: { id: string, name: string, email: string },   // PlatformAdminData (shell)
  tenants: {                                             // paginator
    data: Array<{
      id: string,
      name: string,
      subdomain: string,          // host minus the central domain suffix
      status: string,             // TenantStatus->value
      status_label: string,       // bilingual ->label()
      status_color: string,       // ->color() token
      plan: string,               // TenantPlan->value
      plan_label: string,         // ->label()
      owner_email: string,        // from the central tenants.data column
      created_at: string          // ISO-8601
    }>,
    current_page: number,
    last_page: number,
    per_page: number,
    total: number
  },
  filters: { status: string | null, plan: string | null },  // echo of applied filters
  status_options: Array<{ value: string, label: string }>,  // every TenantStatus
  plan_options: Array<{ value: string, label: string }>,    // every TenantPlan
  counts: {                       // UNFILTERED per-status totals
    total: number,
    by_status: Record<string, number>   // { active: n, suspended: n, pending: n, failed: n, archived: n }
  }
}
```

### 7.3 `Platform/Tenants/Show` (GET central `/admin/tenants/{tenant}`, auth:admin)
```
{
  admin: { id: string, name: string, email: string },   // PlatformAdminData (shell)
  tenant: {
    id: string,
    name: string,
    subdomain: string,
    status: string,             // TenantStatus->value
    status_label: string,
    status_color: string,
    plan: string,               // TenantPlan->value
    plan_label: string,
    owner_email: string,
    created_at: string,
    seat_limit: number,         // plan->seatLimit() (0 = unlimited)
    price_cents: number,        // plan->priceCents() (int cents)
    features: Array<{ value: string, label: string, active: boolean }>, // resolved per plan
    over_seat_limit: boolean,   // grandfathering flag (§5 S-PLAN); false when seat data N/A
    seats_over: number          // max(member_count - seat_limit, 0); 0 when unlimited/under
  },
  allowed_transitions: Array<{ value: string, label: string }>, // legal next statuses (canTransitionTo)
  assignable_plans: Array<{ value: string, label: string }>,    // every TenantPlan (any plan assignable)
  can: {
    suspend: boolean,           // status->canTransitionTo(Suspended)
    reactivate: boolean,        // status->canTransitionTo(Active) && status === Suspended
    change_plan: boolean        // true (any non-archived tenant)
  }
}
```

**PII/secret guard:** `owner_email` is operator-only console data (the platform admin
legitimately sees it) — it is exposed ONLY on these `auth:admin` pages, NEVER on a
public/tenant prop (the slice-001 PropContractTest already asserts the public
provisioning page does NOT leak `owner_email`; keep that green). No `password` /
`remember_token` ever appears in any admin or tenant prop. The `over_seat_limit` /
`seats_over` numbers may require a per-tenant member count — see §10 OQ-1 for the
central-only way to obtain it WITHOUT entering tenant context.

## 8. AUTH / GATING MATRIX (server-side, falsifiable)

| Operation                         | platform admin | tenant user | guest (central) |
|-----------------------------------|:--------------:|:-----------:|:---------------:|
| Reach platform login (GET)        |      ✅        |     ✅      |       ✅        |
| Authenticate (POST login)         |  ✅ (central)  | ❌ (no row) |   ❌ (no row)   |
| Dashboard / tenant detail (read)  |      ✅        |  ❌ → 302   |    ❌ → 302     |
| Suspend / reactivate / plan (write)|     ✅        |  ❌ → 302   |    ❌ → 302     |
| Reach the tenant app on a tenant subdomain | n/a (central identity) | ✅ if Active | n/a |

- The console route group is gated `auth:admin` (the dedicated central guard). A
  guest hitting it → **302** to `route('platform.login')` (the central host — no
  tenant-host rewrite needed; the console is single-host, unlike slice 002's
  per-tenant redirect).
- Tenant-user impossibility is **structural**, not a check: the `admin` guard's
  provider is `platform_admins` on the **central** connection; tenant users live in
  per-tenant DBs and are simply absent. Asserted in S4.
- Illegal status transitions and (future) over-limit handling are **domain guards**
  in the Actions → graceful **302 + error**, never 500.

## 9. TEST LIST (falsifiable)

Feature (central pages use the configured test/central connection + `RefreshDatabase`;
the suspended-block + any provisioned-tenant test uses **real PostgreSQL**, no
`RefreshDatabase` — `CREATE DATABASE` cannot run in a tx — mirroring
`TenantIdentificationTest` / `CrossTenantIsolationTest`):

1. **`PlatformAuthTest`** — login happy path (S1): seed a platform admin, `POST`
   central `/admin/login` → 302 to dashboard, authenticated on the `admin` guard.
2. **`PlatformAuthTest`** — bad credentials (S2): 302 + `assertSessionHasErrors('email')`,
   still a guest, never 422.
3. **`PlatformAuthTest`** — console gate (S3): guest → dashboard / detail / write
   routes → 302 to platform login; authed admin → 200 (reads).
4. **`PlatformAuthTest`** — logout (S1 tail): authed `POST /admin/logout` → 302 to
   login, session invalidated.
5. **`PlatformAdminIsolationTest`** (the crown, S4): create a tenant user U in a
   provisioned tenant A (slice-002 path) with password P; `POST {U.email, P}` to the
   **central** `/admin/login` → **fails** (guest, session error), because the `admin`
   guard queries `platform_admins` on the central connection. Conversely, a seeded
   platform admin's creds do NOT authenticate tenant A's `web` guard. Real PG.
6. **`PlatformDashboardTest`** — lists all tenants (S5): seed N tenants (factory,
   central) across statuses/plans; dashboard returns all N with the exact summary
   fields + the unfiltered `counts.by_status` map; central-only (no tenant DB hit).
7. **`PlatformDashboardTest`** — filters (S6): `?status=suspended&plan=team` narrows
   the list; counts stay unfiltered totals.
8. **`PlatformTenantDetailTest`** — detail (S7): the show page exposes the exact
   `tenant` shape, `seat_limit`/`price_cents` from the plan, resolved `features`,
   `allowed_transitions` from `canTransitionTo()`, and `can.{suspend,reactivate,change_plan}`.
9. **`SuspendTenantTest`** — suspend happy path (S8): admin suspends an Active tenant
   → central status `Suspended`, 302 + flash; central-only mutation.
10. **`SuspendTenantTest`** — illegal transition (S11): admin tries to suspend a
    `Pending`/`Failed` tenant (or reactivate an Active one) → 302 + error, status
    unchanged, NOT 500.
11. **`ReactivateTenantTest`** — reactivate happy path (S10): admin reactivates a
    Suspended tenant → central status `Active`, 302 + flash.
12. **`SuspendedTenantBlockedTest`** (the teeth, S9/S10): provision a real tenant
    (CreateDatabase + tenants:migrate), set central status `Suspended`, GET the
    tenant host landing/login → **503**; flip status to `Active`, same GET → **200**.
    Real PG, mirrors `TenantIdentificationTest` provisioning.
13. **`ChangeTenantPlanTest`** — plan + seat recompute (S12): admin changes plan
    Team→Business → central `plan` updated; `seatLimit()` now reflects the new plan;
    a Pennant feature that the new plan unlocks resolves active. 302 + flash.
14. **`ChangeTenantPlanTest`** — downgrade grandfathering (S13): a tenant with M
    members downgraded to a plan with `seatLimit() = N` (`0 < N < M`) → change
    succeeds; detail flags `over_seat_limit: true`, `seats_over: M - N`; no member
    removed. Real PG (needs a member count) OR a seam per §10 OQ-1.
15. **Prop-contract tests** (`PlatformPropContractTest`): `Auth/PlatformLogin`,
    `Platform/Dashboard`, `Platform/Tenants/Show` assert the exact prop keys/shape;
    assert NO `password`/`remember_token` leak (`->missing(...)`).
16. **`PlatformAdminSeederTest` / command test**: `platform:create-admin` (or the
    seeder) mints exactly one admin with a hashed (non-plaintext) password; running
    it idempotently does not duplicate; the seeded admin can log in.
17. **Lang resolution test** (Feature): every new `platform.*` key resolves in `es` +
    `en`; every new React i18n key exists for both locales.
18. **`PlatformAdminModelTest`** (Unit-ish, central DB → Feature): UUIDv7 id minted,
    `password` is a hashed cast (never plaintext), `email` unique.
19. **Arch tests stay green + extended**: strict types; `App\Domain\Platform` is
    HTTP-agnostic (no `Illuminate\Http`); Platform Actions final; Platform DTOs final;
    `App\Domain\Platform` AND `App\Http\Controllers\Central` never import
    `App\Models\User` or `App\Models\Note` (central ↛ per-tenant); the new central
    transition exceptions are final.

> All DB/`__()`-touching tests live in `Feature`, never `Unit`. No real PII —
> fictional admins/tenants only. Do NOT run pest/migrate after generating (shared DB);
> phpstan + tsc are allowed.

## 10. OPEN QUESTIONS

- **OQ-1 — Member count without entering tenant context (for `over_seat_limit`).**
  The grandfathering flag needs each tenant's live member count, but the law forbids
  the central console from entering tenant context / querying per-tenant models.
  Three options, in preference order: **(a)** denormalize a `member_count` onto the
  central `tenants.data` column, bumped by slice-002's `CreateMember`/`RemoveMember`
  Actions (cheap, central-readable, the recommended path — note it touches slice-002
  Actions); **(b)** compute it lazily in `ChangeTenantPlan` via a single read-only
  `DB::connection(tenantConnection)->table('users')->count()` WITHOUT booting tenancy
  (a raw count is not "operating a per-tenant model", but blurs the arch rule — the
  arch test forbids importing `App\Models\User`, which a raw query does not); **(c)**
  ship `over_seat_limit` as **always false this slice** and defer the count to the
  audit/billing slice. **Recommendation: (a)** if slice-002 Actions may be touched,
  else **(c)**. Confirm with Carlos before implementing.
- **OQ-2 — Suspended-tenant response: 503 vs 403.** This spec uses **503 Service
  Unavailable** (maintenance semantics, matching stancl's `CheckTenantForMaintenanceMode`
  precedent and a `Retry-After`-friendly story). The orchestrator prompt mentioned
  "403/maintenance". 503 is the better fit (the tenant exists and is valid, it is just
  not currently serving). Confirm 503 is acceptable; 403 is a one-line change if not.
- **OQ-3 — First-admin bootstrap channel.** MVP ships a `platform:create-admin`
  console command (interactive) AND a `.env`-driven seeder for CI/staging. Confirm
  whether the seeder should run in the central `DatabaseSeeder` (handy for `db:seed`)
  or stay opt-in (safer — never auto-mint an admin in an unexpected environment).
  Default chosen: **opt-in command + a seeder gated on `PLATFORM_ADMIN_EMAIL` being
  set**, never unconditional.
- **OQ-4 — Login route prefix.** Chosen: console under `/admin` on the central host
  (`/admin/login`, `/admin`, `/admin/tenants/{tenant}`). Confirm `/admin` is fine vs
  a dedicated host (a separate `admin.` subdomain is DEFERRED — single central host
  this slice).
