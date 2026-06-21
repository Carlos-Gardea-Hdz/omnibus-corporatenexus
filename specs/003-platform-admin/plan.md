# Plan 003 — Central Platform Admin (the operator console)

- **Spec:** `specs/003-platform-admin/spec.md`
- **Gate (CLAUDE.md § No-negociables):** `declare(strict_types=1)` + `final` + explicit
  return types everywhere; `readonly` VO/DTO; backed enums + helpers (no magic
  strings); UUIDv7 for new ids; Spatie Data DTOs (web validation → 302, never 422);
  Inertia props snake_case; central migrations reversible; Money/seats int; Actions =
  one op + `DB::transaction`; Domain ↛ `Illuminate\Http`; **central ↛ per-tenant
  models**; PHPStan **level 9** (repo); Pint clean; `typescript:transform` + `tsc
  --noEmit` clean; DB/`__()` tests in `Feature`. NO real PII.

## 1. ARCHITECTURE

A new CENTRAL-context domain `app/Domain/Platform`, parallel to `app/Domain/Tenancy`,
holding the operator identity + the tenant-management Actions. Everything runs on the
**central** connection (`config('tenancy.database.central_connection')`); tenancy is
NEVER initialized in this slice. A dedicated `admin` auth guard isolates the operator
identity from the tenant `web` guard. The only cross-domain reuse is the central
`Tenant` registry model + `TenantStatus`/`TenantPlan`/`TenantFeature` enums (read +
the status flip) — never a per-tenant model.

The suspended-tenant block is the one tenant-route change: an `EnsureTenantIsActive`
middleware appended to the tenant group, modeled on stancl's own
`CheckTenantForMaintenanceMode` (it runs AFTER identification, reads `tenant()->status`,
and 503s a non-Active tenant).

## 2. DATA MODEL / MIGRATIONS (central set: `database/migrations`, reversible)

### 2.1 `platform_admins` (new central table)
- `2026_06_2x_000000_create_platform_admins_table.php`
- Columns: `uuid id` PK (UUIDv7, app-generated — mirror the Tenant `Uuid7Generator`
  pattern / `HasUuids`-style boot); `string name`; `string email` UNIQUE;
  `string password`; `rememberToken()`; `timestamps()`.
- `down()` drops the table. Reversible.
- NOT a tenant migration — lives in `database/migrations` (the central set).

### 2.2 TenantStatus::Suspended
- **No migration.** The enum already has `Suspended` and `status` is a promoted
  custom column (`Tenant::getCustomColumns()`), stored as a string in the central
  `tenants` table. Suspending = updating that column. Confirm only; build nothing.

### 2.3 `member_count` denormalization (CONDITIONAL — see spec §10 OQ-1)
- ONLY if OQ-1 option (a) is approved: a nullable `member_count` rides the central
  `tenants.data` virtual column (no migration — `data` is schemaless) and is bumped by
  slice-002 `CreateMember`/`RemoveMember`. Do NOT build until Carlos confirms; default
  to OQ-1 (c) (`over_seat_limit` = false) if unconfirmed.

## 3. DOMAIN LAYER (`app/Domain/Platform`)

### Models/
- `PlatformAdmin extends Authenticatable` — central connection
  (`protected $connection = central`), UUIDv7 id (boot a `creating` hook or reuse the
  tenancy UUID generator), `$fillable = [name, email, password]`, `casts()` →
  `password => 'hashed'`, `@property` PHPDoc, `newFactory(): PlatformAdminFactory`,
  hidden `password`/`remember_token`. Implements no per-tenant coupling.

### Data/ (Spatie `Data`, `#[TypeScript]`, `final`)
- `PlatformLoginData` — `#[Required, Email, Max(255)] email`, `#[Required, Max(255)]
  password`, `bool remember = false`. (Mirror `Membership\Data\LoginData`.)
- `PlatformAdminData` — `id, name, email` (NEVER password). `fromModel(PlatformAdmin)`.
- `TenantSummaryData` — the dashboard row (spec §7.2 `tenants.data[]`).
  `fromModel(Tenant)`; derives `subdomain` by stripping the central-domain suffix from
  the first domain; `status_label/color`, `plan_label` via the enum helpers.
- `TenantDetailData` — the detail shape (spec §7.3 `tenant`); `fromModel(Tenant)`
  enriched with `seat_limit`, `price_cents`, `features` (resolve each
  `TenantFeature` against the plan), `over_seat_limit`, `seats_over` (per OQ-1).

### Enums/
- Reuse `Tenancy\Enums\{TenantStatus, TenantPlan, TenantFeature}`. No new enum.

### Exceptions/ (`final`)
- `TenantTransitionException` — `cannotTransition(TenantStatus $from, TenantStatus
  $to): self` (illegal lifecycle transition; rendered 302+error in bootstrap/app.php).

### Actions/ (`final`, one op, `DB::transaction` on the central connection)
- `AuthenticatePlatformAdmin` — `handle(PlatformLoginData): PlatformAdmin`.
  `Auth::guard('admin')->attempt([...], $remember)`; on failure throw a generic
  non-enumerating `ValidationException::withMessages(['email' => __('auth.failed')])`.
  Session regen/redirect is the controller's job (mirror `AuthenticateMember`). May
  use the `Auth` facade (allowed in an auth Action; stays off `Illuminate\Http`).
- `SuspendTenant` — `handle(Tenant): Tenant`. Guard
  `$tenant->status->canTransitionTo(TenantStatus::Suspended)` → else throw
  `TenantTransitionException`. In a central tx: `$tenant->update(['status' =>
  Suspended])`. Returns the tenant. (Audit hook seam noted — deferred.)
- `ReactivateTenant` — symmetric, guard `canTransitionTo(Active)` + require current
  `Suspended`; central tx flip to `Active`.
- `ChangeTenantPlan` — `handle(Tenant, TenantPlan $newPlan): Tenant`. Central tx:
  `$tenant->update(['plan' => $newPlan])`. Seat limit is NOT stored (read live from
  the plan). Feature gates resolve from the plan (Pennant scopes on the tenant; no
  stored copy). If OQ-1(a): recompute `over_seat_limit` for the response only — do not
  remove members.

## 4. HTTP LAYER

### Guard config (`config/auth.php`)
- Add guard `admin` → driver `session`, provider `platform_admins`.
- Add provider `platform_admins` → driver `eloquent`, model `PlatformAdmin::class`.
- (The default `web` guard stays the tenant `users` provider, unchanged.)

### Controllers (`app/Http/Controllers/Central`, `final`, anemic ≤15 lines/method)
- `Auth/PlatformLoginController` — `create(): Response` (Inertia `Auth/PlatformLogin`,
  no props); `store(PlatformLoginData, AuthenticatePlatformAdmin): RedirectResponse`
  (handle → `request()->session()->regenerate()` →
  `redirect()->intended(route('platform.dashboard'))`); `destroy()` →
  `Auth::guard('admin')->logout()` + invalidate + regenerateToken → redirect
  `platform.login`. Mirror the CMS `Auth/LoginController`. NEVER imports
  `App\Models\User`.
- `PlatformDashboardController@index` — read filters from `request()` (status/plan),
  build the central `Tenant` query (`with('domains')`, `orderByDesc('created_at')`,
  `paginate`), map to `TenantSummaryData`, compute the UNFILTERED `counts.by_status`
  (`Tenant::query()->selectRaw('status, count(*)')->groupBy('status')`), pass
  `status_options`/`plan_options`, render `Platform/Dashboard` with the
  `PlatformAdminData` shell. Central-only.
- `PlatformTenantController` —
  - `show(Tenant $tenant): Response` → `Platform/Tenants/Show` with `TenantDetailData`,
    `allowed_transitions` (filter `TenantStatus::cases()` by
    `$tenant->status->canTransitionTo()`), `assignable_plans`, `can`.
  - `suspend(Tenant, SuspendTenant): RedirectResponse` → handle → back/redirect detail
    + success flash.
  - `reactivate(Tenant, ReactivateTenant): RedirectResponse` → symmetric.
  - `changePlan(Tenant, ChangeTenantPlanData, ChangeTenantPlan): RedirectResponse` —
    a tiny `ChangeTenantPlanData` DTO (`#[Required, Enum(TenantPlan::class)] plan`)
    resolves + validates the target plan (302 on bad input). Handle → flash.
  - Route-model binding resolves `Tenant` on the central connection (UUIDv7 key), as
    the existing provisioning route already does.

### Routes (`routes/central.php`)
- Keep the existing public group (`/`, `/register`, `/provisioning/{tenant}`)
  UNAUTHENTICATED.
- Guest admin auth:
  - `GET  /admin/login`  → `PlatformLoginController@create`  name `platform.login`
    (wrap in `->middleware('guest:admin')` so an authed admin skips the form).
  - `POST /admin/login`  → `@store` `->middleware('throttle:6,1')` name
    `platform.login.store`.
- Console (`->middleware('auth:admin')` group, prefix `/admin`):
  - `POST /admin/logout` → `@destroy` name `platform.logout`.
  - `GET  /admin` → `PlatformDashboardController@index` name `platform.dashboard`.
  - `GET  /admin/tenants/{tenant}` → `@show` name `platform.tenants.show`.
  - `PATCH /admin/tenants/{tenant}/suspend` → `@suspend` name `platform.tenants.suspend`.
  - `PATCH /admin/tenants/{tenant}/reactivate` → `@reactivate` name
    `platform.tenants.reactivate`.
  - `PATCH /admin/tenants/{tenant}/plan` → `@changePlan` name `platform.tenants.plan`.
- The whole central file is already constrained to the central host in
  `bootstrap/app.php` (`Route::domain(config('app.central_domain'))`). The `redirectGuestsTo`
  closure in `bootstrap/app.php` builds `/login` on the request host — that is the
  TENANT redirect; the `admin` guard needs the CONSOLE login. Wire it via the guard's
  own redirect (a `redirectUsersTo`/`authenticate` unauthenticated handler keyed to
  the guard) OR an exception render mapping `AuthenticationException` with guard
  `admin` → `redirect()->guest(route('platform.login'))`. Prefer the framework
  `Authenticate` middleware's `redirectTo` resolving to `platform.login` when the
  guard is `admin` (single central host → `route()` is correct here, no host rewrite).

### Suspended-tenant block (`app/Http/Middleware/EnsureTenantIsActive.php`)
- `final`, `handle(Request, Closure)`: `$tenant = tenant();` if `! $tenant?->status->isServable()`
  → `abort(503)` (spec OQ-2; `Retry-After` optional). Append to the tenant group in
  `routes/tenant.php` AFTER `InitializeTenancyByDomain` + `PreventAccessFromCentralDomains`
  so it sees the identified tenant. (Do NOT register globally — it must only run in
  tenant context.) Add to the high-priority tenancy middleware list if ordering needs it.

### Exception rendering (`bootstrap/app.php`)
- Add `$exceptions->render(TenantTransitionException ...)`: web → `back()->withErrors([
  'status' => $e->getMessage()])`; JSON → 422. Mirrors the slice-002 Membership
  exception handlers already present. Never a 500.

## 5. SEEDER / COMMAND
- `app/Console/Commands/CreatePlatformAdmin.php` (`platform:create-admin`) — prompts
  name/email/password (or accepts options), creates one `PlatformAdmin` with a hashed
  password, idempotent on email. `final`.
- `database/seeders/PlatformAdminSeeder.php` — gated on `env('PLATFORM_ADMIN_EMAIL')`
  set (else no-op); `firstOrCreate` on email with a hashed `env('PLATFORM_ADMIN_PASSWORD')`.
  Wire into the central `DatabaseSeeder` per OQ-3 (default: opt-in, never unconditional).
- `database/factories/PlatformAdminFactory.php` — fictional admin, hashed password.

## 6. FRONTEND (React 19 + Inertia 2 + TS + Tailwind, dark/light)
- Pages: `resources/js/Pages/Auth/PlatformLogin.tsx`,
  `resources/js/Pages/Platform/Dashboard.tsx`,
  `resources/js/Pages/Platform/Tenants/Show.tsx`. Props typed from the generated
  `TenantSummaryData`/`TenantDetailData`/`PlatformAdminData` `.d.ts` (types only).
  `useForm` posts to the named routes; web errors arrive as session errors (302).
  Status/plan badges use the enum `color()` token. Dark/light via the existing
  `<html>` class strategy (project law). Bilingual copy via the i18n hook.
- `php artisan typescript:transform` after the DTOs; `tsc --noEmit` clean.

## 7. LANG (`lang/{es,en}/platform.php` + React i18n dict)
- Keys: `platform.login.{title,email,password,submit,failed?}`,
  `platform.dashboard.{title,filters.status,filters.plan,empty}`,
  `platform.tenant.{detail_title,owner_email,created_at,seat_limit,price,features,
  over_limit}`, `platform.actions.{suspend,reactivate,change_plan,
  suspend_success,reactivate_success,plan_success,illegal_transition}`. EVERY key in
  BOTH files + the React dict; a resolution test asserts both locales.

## 8. FILES TO TOUCH / CREATE (summary)
- **New:** `database/migrations/..._create_platform_admins_table.php`;
  `app/Domain/Platform/{Models/PlatformAdmin, Data/{PlatformLoginData,PlatformAdminData,
  TenantSummaryData,TenantDetailData,ChangeTenantPlanData}, Exceptions/TenantTransitionException,
  Actions/{AuthenticatePlatformAdmin,SuspendTenant,ReactivateTenant,ChangeTenantPlan}}`;
  `app/Http/Controllers/Central/{Auth/PlatformLoginController,PlatformDashboardController,
  PlatformTenantController}`; `app/Http/Middleware/EnsureTenantIsActive`;
  `app/Console/Commands/CreatePlatformAdmin`; `database/{factories/PlatformAdminFactory,
  seeders/PlatformAdminSeeder}`; `lang/{es,en}/platform.php`; the three React pages;
  the test files in spec §9.
- **Edit:** `config/auth.php` (admin guard+provider); `routes/central.php` (admin
  routes); `routes/tenant.php` (append `EnsureTenantIsActive`); `bootstrap/app.php`
  (`TenantTransitionException` render + admin-guard unauthenticated redirect);
  `tests/Unit/ArchTest.php` (Platform arch guards); `database/seeders/DatabaseSeeder.php`
  (opt-in admin seeder, OQ-3). **Conditional (OQ-1(a) only):** slice-002
  `CreateMember`/`RemoveMember` (bump central `member_count`).

## 9. RISKS / NOTES
- **R1 — admin-guard redirect vs slice-002's tenant-host `redirectGuestsTo`.** The
  existing closure rewrites to the request host for the TENANT login; the console is
  single central-host, so the `admin` guard must redirect to `route('platform.login')`
  (absolute, central host) — do not let the tenant-host rewrite hijack console
  guests. Resolve at the `Authenticate` middleware `redirectTo`/guard level (plan §4).
- **R2 — central ↛ per-tenant arch.** Easiest violation is reaching for a member count
  in `ChangeTenantPlan`/`TenantDetailData`. Honor OQ-1; the arch test forbids importing
  `App\Models\User`/`Note` from `App\Domain\Platform` + `App\Http\Controllers\Central`.
- **R3 — suspended block ordering.** `EnsureTenantIsActive` MUST run after
  identification (so `tenant()` is set) and is tenant-group-only (never central — a
  central request has no tenant and would falsely 503/throw).
- **R4 — `owner_email` exposure.** Legitimate on `auth:admin` console props ONLY; keep
  the slice-001 public-provisioning `->missing('owner_email')` contract green.
- **R5 — no migrate/pest after generating** (shared DB); phpstan + tsc only. Real-PG
  tests (suspended block, isolation) follow the no-RefreshDatabase provisioning pattern.
