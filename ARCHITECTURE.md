# Architecture — CorporateNexus

Target structure and the key decisions taken from the vault module
`~/.claude/.agent-rules/multitenancy.md` (and `security-2026.md`).

## 1. Tenancy strategy

- **Database-per-tenant by default** via `stancl/tenancy` v3.10. Isolation is a
  product feature for an enterprise SaaS, so each tenant gets a dedicated
  PostgreSQL database. The design is **hybrid-ready**: `TenantPlan::Enterprise`
  is flagged `requiresDedicatedDatabase()`, and a tenant can be migrated between
  strategies without code changes.
- **Central database holds the registry only** — tenants, domains, billing,
  feature flags and central users. Deleting a tenant database must never destroy
  billing/audit data. Tenant databases hold per-tenant users + app data.
- **Tenant id = UUIDv7** (`Uuid7Generator`), wired through
  `config/tenancy.php → id_generator`. Sortable, good index locality.

## 2. Code layout (DDD-Lite)

```
app/
  Domain/
    Tenancy/
      Models/        Tenant (central registry), Uuid7Generator
      Actions/       CreateTenant (one business op, DB::transaction)
      Data/          CreateTenantData (input DTO), TenantData (output DTO)
      Enums/         TenantStatus, TenantPlan, TenantFeature
      Events/        TenantCreated
      Exceptions/    TenantProvisioningException
    Shared/
      ValueObjects/  cross-domain VOs
  Http/
    Controllers/Central/   TenantRegistrationController (anemic)
    Controllers/Tenant/    DashboardController (anemic)
    Middleware/            SecurityHeaders, HandleInertiaRequests
  Providers/                AppServiceProvider (feature-flag definitions)
```

- **Controllers are anemic** (≤15 lines): validated DTO in → Action → response.
- **Actions** own all writes; multi-table work is wrapped in `DB::transaction()`
  on the **central** connection.
- **DTOs** extend Spatie `Data`, validate via attributes, and are marked
  `#[TypeScript]` — the single source of truth for both server validation and
  the generated frontend types (`resources/js/types/generated.d.ts`).
- **Enums** are backed, with `label()` (bilingual), `color()`, and
  `canTransitionTo()` for lifecycle safety. No magic strings.

## 3. Routing & request lifecycle

- `routes/central.php` — constrained to `config('app.central_domain')` in
  `bootstrap/app.php`. Registration, billing, admin. Runs against the central DB.
- `routes/tenant.php` — `web` + `InitializeTenancyByDomain` +
  `PreventAccessFromCentralDomains`. Tenancy bootstrappers swap the DB / cache /
  filesystem / queue connections to the tenant automatically.
- `routes/web.php` is intentionally empty (placeholder for `withRouting`).

## 4. Migrations

- `database/migrations/` — central: `tenants`, `domains`, `features` (Pennant),
  `cache`, `jobs`. Run once with `php artisan migrate`.
- `database/migrations/tenant/` — per-tenant: `users`, `sessions`,
  `password_reset_tokens`. Run on every tenant DB with
  `php artisan tenants:migrate`. Add this step to deploy so no tenant DB drifts.
- Every migration is reversible (project law).

## 5. Feature flags (Pennant)

- Flags are **scoped to the tenant**, not the user (`Feature::for($tenant)`).
  `Tenant` implements `FeatureScopeable::toFeatureIdentifier()` so flags
  serialize by id for the `database` driver.
- Plan-derived flags are resolved from **central plan data** in
  `AppServiceProvider::defineFeatureFlags()` — never branch on hardcoded plan
  ifs in app code, and never read plan state from inside a tenant DB.
- Tests use the `array` driver (`PENNANT_STORE=array` in `phpunit.xml`).

## 6. Frontend (Inertia + React + TS)

- `@inertiajs/vite`-style bootstrap in `resources/js/app.tsx`, SSR entry in
  `ssr.tsx` (`vite build --ssr` → `bootstrap/ssr`).
- **Type-safety**: Spatie DTOs → `php artisan typescript:transform` →
  `resources/js/types/generated.d.ts` (ambient `App.Domain.*` namespaces),
  surfaced through `resources/js/types/index.ts`. Shared Inertia props are typed
  via the v2 `InertiaConfig` augmentation in `types/inertia.d.ts`.
- **UI/UX**: dark/light via the `.dark` class on `<html>` (no-flash inline
  script with CSP nonce); bilingual ES/EN through `lib/i18n`; WCAG 2.2 AA
  (visible focus, skip link, focus management on navigation, reduced-motion).
- The app shell (`Layouts/AppLayout`) surfaces tenant context (org name + plan).

## 7. Security baseline

- `SecurityHeaders` middleware: per-response CSP nonce, `nosniff`,
  `Referrer-Policy`, COOP/CORP, `Permissions-Policy`, HSTS over TLS. CSP is
  `Content-Security-Policy-Report-Only` first; flip to enforcing once clean.
- Sessions server-side on Valkey; cookie on the apex domain, `HttpOnly`,
  `Secure` (prod), `SameSite=Lax`.
- Rate-limited tenant provisioning; reserved-subdomain allowlist (default-deny).
- Authorization is server-side; client prop gating is UX only.

## 8. Notable version decision

The vault `inertia-react-advanced.md` targets the Inertia **client v3** line.
This scaffold pins `@inertiajs/react ^2` to match the resolved
`inertiajs/inertia-laravel` **v2** server adapter (the server + client lines are
versioned together). Upgrade both in lockstep when moving to the v3 line; the
React 19.2.1+ security pin is already satisfied (resolved 19.2.7).
