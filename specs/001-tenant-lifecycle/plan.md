# Plan 001 — Tenant Lifecycle (technical: HOW)

Gated on CLAUDE.md §No-negociables and AGENTS §5. Implements `spec.md`. Reuse the
foundation — **do not rip out stancl or duplicate the Tenancy domain.**

## A. Architecture decisions

1. **Reuse the foundation signup path.** `CreateTenantData`, `CreateTenant`,
   `TenantRegistrationController`, `Central/Register.tsx`, the central routes and the
   `TenancyServiceProvider` pipeline already exist and are correct. This slice
   **extends** them; it does not re-architect.

2. **Provisioning stays QUEUED, activation at the pipeline tail.** Keep the
   `TenancyServiceProvider` pipeline `CreateDatabase → MigrateDatabase` and **append**
   `Jobs\SeedDatabase` then a new domain job `MarkTenantActive` (Pending→Active). The
   pipeline already `shouldBeQueued(true)`; CREATE DATABASE stays outside any
   transaction. The `CreateTenant` Action keeps its central-only `DB::transaction`
   for the tenants+domains rows (that is registry-only, no CREATE DATABASE) — this is
   already compliant; **do not** wrap the database creation in a transaction.

3. **Status transition via the pipeline, not the controller.** `MarkTenantActive`
   loads the central tenant, guards `status->canTransitionTo(Active)`, sets `Active`.
   This keeps central status authority in the central DB; the job runs after the
   tenant DB is migrated+seeded.

4. **Central provisioning-status page reads the central registry** (no tenant
   context). Route-model-bound `Tenant` (central connection). The poller reloads the
   page until `is_active`.

5. **Tenant landing reads tenant-owned data** (`notes` count) to prove context.
   `notes_count` comes from `Note::count()` while inside tenant context.

6. **Identification middleware:** use `InitializeTenancyByDomain` (already on
   `routes/tenant.php`) — the central signup stores the FQDN
   `{subdomain}.{central_domain}` in `domains`, so by-domain identification matches it
   directly. Keep `PreventAccessFromCentralDomains`. (BySubdomain is equivalent given
   the FQDN domain rows; stay with ByDomain to match the foundation + isolation test.)

## B. Files to touch / create

### Enums
- **Edit** `app/Domain/Tenancy/Enums/TenantPlan.php` — add
  `public function priceCents(): int` (Free=0, Team=2900, Business=9900,
  Enterprise=0). Integer cents; document Enterprise=0 = "contact sales".

### Data (DTOs)
- **Edit** `app/Domain/Tenancy/Data/CreateTenantData.php` — extend the reserved
  allowlist in `rules()` to the full §5 set. (Validation otherwise already correct:
  Min/Max/AlphaDash/Lowercase/Email.)
- **New (optional, preferred)** `app/Domain/Tenancy/Data/PlanOptionData.php` —
  `#[TypeScript] final` DTO `{string value, string label, int price_cents, int seat_limit}`
  with `::fromEnum(TenantPlan): self`; collection feeds `Central/Register` props.
  (snake_case via Spatie name mapping or explicit property names.)

### Actions / Jobs
- **New** `app/Domain/Tenancy/Jobs/MarkTenantActive.php` — `final`, queued; ctor takes
  `Tenant $tenant` (or its id); `handle()` reloads central tenant, guards
  `canTransitionTo(Active)`, persists `Active`. Idempotent (no-op if already Active).
- **Edit** `app/Providers/TenancyServiceProvider.php` — pipeline becomes
  `[CreateDatabase, MigrateDatabase, SeedDatabase, MarkTenantActive]` (still
  `->shouldBeQueued(true)`). `MarkTenantActive` must run in **central** context — it
  is the last stage and writes the central registry; ensure it does not depend on
  tenant context (load by id on the central connection).

### Seeders
- **New** `database/seeders/TenantDatabaseSeeder.php` — seeds **fictional** demo data
  into the tenant DB (e.g. 3 demo `notes`). No real names/PII. Wire it via
  `config/tenancy.php → seeder_parameters['--class']` (set to
  `Database\Seeders\TenantDatabaseSeeder`) so stancl's `SeedDatabase` job uses it.
- Confirm `database/seeders/DatabaseSeeder.php` (central) is untouched / safe.

### Controllers (anemic, ≤15 lines, final)
- **Edit** `app/Http/Controllers/Central/TenantRegistrationController.php`:
  - `create()` → render `Central/Register` with `plans` prop (collection of
    `PlanOptionData::fromEnum` over `TenantPlan::cases()`).
  - `store(CreateTenantData, CreateTenant)` → after `handle`, redirect to
    `route('central.provisioning', $tenant)` (302). Drop the `->with('tenant', …)`
    flash; status page reads the registry by id.
- **New** `app/Http/Controllers/Central/ProvisioningStatusController.php` —
  `show(Tenant $tenant)` (route-model-bound, central) → render `Central/Provisioning`
  with `tenant` (`TenantData`), `tenant_url` (built from the tenant's domain when
  `status->isServable()`, else `null`), `is_active`.
- **Edit** `app/Http/Controllers/Tenant/DashboardController.php` → rename intent to a
  **landing**: `index()` renders `Tenant/Landing` with `tenant` (`TenantData`) +
  `notes_count` (`Note::count()`). (Keep class name or rename to
  `LandingController`; if renamed, update `routes/tenant.php` + any test. Prefer a new
  `LandingController` and leave `DashboardController` for the later auth slice — note
  in tasks.)

### Routes
- **Edit** `routes/central.php`:
  - keep `/`, `/register` (GET+POST throttled).
  - add `GET /provisioning/{tenant}` →
    `[ProvisioningStatusController::class, 'show']->name('central.provisioning')`.
    Route-model binding resolves `Tenant` on the central connection.
- **Edit** `routes/tenant.php` → point `/` at the landing controller named
  `tenant.landing` (or keep `tenant.dashboard`); keep the `web +
  InitializeTenancyByDomain + PreventAccessFromCentralDomains` group.

### Frontend (Inertia + React + TS)
- **Edit** `resources/js/Pages/Central/Register.tsx` — render the `plans` prop as a
  plan picker (radio/select); show `price_cents` formatted (cents→currency) and
  `seat_limit`. Keep `useForm<CreateTenantData>`; the generated `CreateTenantData`
  type now carries `plan: App.Domain.Tenancy.Enums.TenantPlan`. **Type-only** import
  of generated enum types.
- **New** `resources/js/Pages/Central/Provisioning.tsx` — shows status badge
  (`tenant.status` via i18n), and when `is_active` a link to `tenant_url`; while
  pending, a poll-on-load `router.reload()` on an interval (cleared on active),
  respecting `prefers-reduced-motion`.
- **New** `resources/js/Pages/Tenant/Landing.tsx` (or rename Dashboard) — shows
  `tenant.name`, `tenant.plan`, and `notes_count` ("N notes in this workspace") as
  the visible proof of tenant-DB context.
- **Edit** `resources/js/lib/i18n.tsx` — add ES/EN keys (see §C).
- Run `php artisan typescript:transform` to regenerate
  `resources/js/types/generated.d.ts` (adds `PlanOptionData`); ensure
  `resources/js/types/index.ts` re-exports as needed.

### Lang (PHP) — both files
- **Edit** `lang/en/tenancy.php` + `lang/es/tenancy.php` — already hold `status.*` and
  `plan.*`. No new PHP keys strictly required unless the seeder/job emit user-facing
  copy; if a provisioning copy block is added server-side, mirror it in both files.
  (Primary new UI copy lives in the React dictionary — see §C.)

## C. New React i18n keys (ES/EN — add to `dictionary`)
```
'register.plan'            es 'Plan'                         en 'Plan'
'register.plan.seats'      es 'asientos'                     en 'seats'
'register.plan.unlimited'  es 'ilimitados'                   en 'unlimited'
'register.plan.free'       es 'Gratis'                       en 'Free'
'provisioning.title'       es 'Preparando tu organización'   en 'Setting up your organization'
'provisioning.pending'     es 'Aprovisionando…'              en 'Provisioning…'
'provisioning.active'      es '¡Listo!'                      en 'Ready!'
'provisioning.open'        es 'Abrir tu espacio'             en 'Open your workspace'
'provisioning.wait'        es 'Esto puede tardar un momento.' en 'This may take a moment.'
'landing.title'            es 'Inicio'                        en 'Home'
'landing.welcome'          es 'Bienvenido a'                  en 'Welcome to'
'landing.notes_count'      es 'notas en este espacio'        en 'notes in this workspace'
```
(Reuse existing `dashboard.*`, `register.*`, `status.*`/`plan.*` where they fit.)

## D. Data model / migrations

- **No new migrations required.** Central `tenants`/`domains`/`features` and tenant
  `users`/`notes` already exist and are reversible. The slice adds behavior, a job,
  a seeder, controllers, routes, pages — not schema. (If a future need adds a
  `provisioned_at` timestamp it must be a reversible central migration; **not** in
  this slice.)

## E. Risks / guards

- **Activation context:** `MarkTenantActive` must write the **central** tenant row.
  Load `Tenant` by id explicitly; do not assume tenant context is active when the job
  runs (it runs after `SeedDatabase`, which ends tenant context).
- **CREATE DATABASE ∉ transaction:** never move provisioning into the Action's
  `DB::transaction`. Tests drain the pipeline synchronously **outside** a transaction
  (mirror `CrossTenantIsolationTest`) or fake the queue and assert dispatch.
- **Central↛tenant coupling:** the status controller reads only the central registry;
  the landing controller reads only tenant data — arch tests must stay green.
- **Prop snake_case:** `price_cents`, `seat_limit`, `tenant_url`, `is_active`,
  `notes_count` — assert via prop-contract tests.
- **Enum .d.ts type-only:** never value-import the generated enum types in TSX.
- **Seeder PII:** demo notes only — no real names/emails/identifiers (project law).

## F. Verification (read-only here; do not run pest/migrate on shared DB)

- `php artisan typescript:transform` (regen types), `pnpm type-check` (tsc), Pint
  `--test`, PHPStan level 9. Full Pest suite (incl. the new isolation + pipeline
  tests against real PG) runs in CI / by the implementer, not the architect.

## G. Task ordering (preview for tasks.md)

Phase 1 (sequential core): TenantPlan.priceCents → CreateTenantData reserved list →
PlanOptionData → MarkTenantActive job → TenancyServiceProvider pipeline →
TenantDatabaseSeeder + tenancy.php seeder_parameters → ProvisioningStatusController +
route → TenantRegistrationController redirect + plans prop → Landing controller +
tenant route → React pages (Register edit, Provisioning new, Landing new) → i18n keys
→ typescript:transform.
Phase 2 [P]: the §9 test list + lang resolution test + prop-contract tests + arch
test re-check.
