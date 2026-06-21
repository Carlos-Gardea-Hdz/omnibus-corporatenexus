# Spec 001 — Tenant Lifecycle (signup → queued provisioning → identification → tenant landing)

- **Status:** Draft (architect contract)
- **Domain:** `app/Domain/Tenancy`
- **Engine:** stancl/tenancy v3 (ADR-001), DB-per-tenant on PostgreSQL 18
- **Author:** Architect agent — 2026-06-20
- **Grounding:** Biblia §4.5; ADR-001; ARCHITECTURE.md; NEXT_STEPS.md; the existing
  foundation in `app/Domain/Tenancy/*`, `app/Providers/TenancyServiceProvider.php`,
  `tests/Feature/Tenancy/CrossTenantIsolationTest.php`.

## 1. WHAT & WHY

CorporateNexus is a DB-per-tenant SaaS. This slice delivers the **end-to-end tenant
lifecycle MVP** so a visitor can self-serve an organization and reach a working
(unauthenticated) tenant app:

1. **Central signup** (public, on the central domain): a visitor submits org name +
   desired subdomain + chosen plan. We validate (format, reserved-subdomain
   default-deny, uniqueness), create the **central registry** tenant row in
   `TenantStatus::Pending`, and dispatch the **queued** provisioning pipeline.
2. **Queued provisioning**: a worker creates the physical tenant database, runs the
   tenant migrations, seeds it, and **flips the tenant to `Active`**. Until the
   worker drains the pipeline the tenant stays `Pending`.
3. **Provisioning status**: after signup the visitor lands on a central
   provisioning-status page that reflects `Pending`/`Active` and (when active)
   links to the tenant subdomain.
4. **Tenant identification + landing**: a request to `{subdomain}.{central_domain}`
   is identified by the stancl subdomain/domain middleware, boots the tenant DB
   context, and renders a landing page that **proves tenant-DB context** (shows the
   tenant name + a live count from a tenant-owned table).

**Why this shape:** it exercises every load-bearing concept (central vs tenant
context, queued CREATE DATABASE, identification middleware, isolation) on the
smallest surface, so later slices (auth, billing, workspaces) build on a proven core.

## 2. IN SCOPE

- Central signup page, controller, route, DTO validation (reuse + extend the
  foundation's `CreateTenantData` / `TenantRegistrationController` / `CreateTenant`).
- A **queued provisioning pipeline** that ends by transitioning `Pending → Active`,
  plus a tenant **seeder** (fictional demo data only).
- A central **provisioning-status** page keyed by tenant id (read from the central
  registry), with a poll-on-load refresh until `Active`.
- Tenant route group gated by stancl identification middleware + a **landing
  controller** proving tenant-DB context.
- Plan **price in integer cents** on `TenantPlan`, surfaced in the signup plan picker.
- Bilingual lang keys (ES/EN) for every new user-facing string, in both PHP lang
  files and the React i18n dictionary, with a resolution test.
- The exact Pest test list in §9.

## 3. DEFERRED (note, do not build)

- **Tenant authentication / login / registration of tenant users** — a later slice
  wires tenant auth (passkeys/MFA per security §8). This slice's tenant landing is
  **public** (no login).
- **MFA / passkeys.**
- **Billing / Stripe / payment capture / plan upgrades** — plan price is modeled in
  cents and displayed, but no charge is taken. `Free` is the only fully self-serve
  plan path required; paid plans still provision (no payment gate this slice).
- **Pennant feature gating on the landing** — the Biblia does not define a
  feature-gated element on the public landing. Pennant stays as-is (foundation).
  **Deferred** for the landing; revisit when the authenticated dashboard arrives.
- **Real-time provisioning push (websockets)** — status page uses a simple
  poll-on-load reload, not broadcasting.

## 4. ACTORS

- **Visitor (central):** anonymous; performs signup; views provisioning status.
- **Tenant user (tenant):** anonymous this slice; views the tenant landing.
- **Queue worker:** drains the provisioning pipeline; runs as a tenant-aware job.

## 5. ACCEPTANCE SCENARIOS (When … Then)

**S1 — Signup happy path.**
When a visitor POSTs a valid `{name, subdomain, ownerEmail, plan}` to the central
`/register`, Then a `tenants` row is created with `status = pending`, a `domains`
row `{subdomain}.{central_domain}` is created, the provisioning pipeline is
dispatched, and the response is a **302 redirect** to the provisioning-status route
for that tenant id (never a 422).

**S2 — Reserved subdomain rejected.**
When the subdomain is in the reserved allowlist (`www`, `app`, `admin`, `api`,
`mail`, `central`, `nexus`, plus added: `dashboard`, `billing`, `support`, `status`,
`assets`, `static`, `cdn`, `blog`, `help`, `docs`, `internal`), Then the response is
a **302 redirect back with a session error on `subdomain`** and **no** tenant row is
created.

**S3 — Invalid subdomain format rejected.**
When the subdomain is too short (<2), too long (>63), uppercase, or contains
characters outside `[a-z0-9-]`, Then **302 + session error on `subdomain`**, no row.

**S4 — Duplicate subdomain rejected.**
When the subdomain resolves to a host already present in `domains`, Then **302 +
session error on `subdomain`**, and exactly one tenant keeps that host.

**S5 — Pipeline provisions the tenant DB and activates it.**
When the queued provisioning pipeline runs for a `Pending` tenant, Then the physical
tenant database exists, the tenant migrations have run (the `notes` table exists),
the tenant seed has run, and the tenant's central status is flipped to `Active`.

**S6 — Tenant identification boots the tenant DB.**
When a request hits the tenant landing route on `{subdomain}.{central_domain}` for a
provisioned tenant, Then tenancy is initialized (the active tenant matches), the page
renders the tenant name and a count read from the **tenant** `notes` table; the same
data is **not** visible from the central context.

**S7 — Cross-tenant isolation for any new tenant-owned data.**
When tenant A writes a row in a tenant-owned table and tenant B is then active, Then
B cannot read/update/delete A's row via Eloquent **or** raw query, and A/B resolve to
distinct physical databases.

**S8 — Provisioning status reflects lifecycle.**
When the status page is requested for a `Pending` tenant, Then it shows the pending
state and does not link to the tenant app; once `Active`, it shows active and links
to the tenant subdomain URL.

**S9 — Prop contracts.**
Each Inertia page receives exactly the snake_case prop shape in §7 (contract test
per page).

**S10 — Lang resolution.**
Every new `__()` key resolves in both `es` and `en`; every new React i18n key exists
for both locales.

## 6. NON-FUNCTIONAL / LAWS

- `declare(strict_types=1)`, `final`, explicit return types; `readonly` VO/DTO.
- Backed enums with helpers; no magic strings. Plan price = **integer cents**.
- UUIDv7 tenant ids (keep foundation generator).
- Web validation → **302 + session errors**, never 422 (Spatie Data via controller
  signature).
- Inertia props **snake_case**; generated enum `.d.ts` is **types only**
  (type-only import).
- **CREATE DATABASE is never wrapped in a transaction.** The central registry write
  may be transactional; provisioning is **queued** and runs outside it.
- Central context must not query tenant data and vice-versa.
- Migrations reversible; central in `database/migrations`, tenant in
  `database/migrations/tenant`.
- PHPStan level 9 (match repo), Pint clean, `typescript:transform` regenerated,
  `tsc --noEmit` clean. DB/`__()` tests live in `Feature`, never `Unit`.

## 7. INERTIA PROP CONTRACTS (snake_case)

### 7.1 `Central/Register` (GET `/register`)
```
{
  plans: Array<{
    value: string,            // TenantPlan->value
    label: string,            // bilingual via ->label()
    price_cents: number,      // integer cents
    seat_limit: number        // 0 = unlimited
  }>
}
```

### 7.2 `Central/Provisioning` (GET `/provisioning/{tenant}`)
```
{
  tenant: {
    id: string,               // UUIDv7
    name: string,
    status: string,           // TenantStatus->value ('pending' | 'active' | ...)
    plan: string              // TenantPlan->value
  },
  tenant_url: string | null,  // https://{subdomain}.{central_domain} when active, else null
  is_active: boolean          // status === active (convenience for the poller)
}
```
(`tenant` is `TenantData`; `tenant_url` + `is_active` are sibling props.)

### 7.3 `Tenant/Landing` (GET `/` on the tenant domain)
```
{
  tenant: {
    id: string,
    name: string,
    status: string,
    plan: string
  },
  notes_count: number         // live COUNT from the TENANT notes table (proves context)
}
```

## 8. DATA / STATUS MODEL

- `TenantStatus`: `Pending → Active` (foundation already allows this transition).
  Provisioning success path: `Pending --(pipeline complete)--> Active`.
- `TenantPlan`: add `priceCents(): int` (Free=0, Team=2900, Business=9900,
  Enterprise=0 for "contact sales"); keep `seatLimit()`.
- No new central columns required (status/plan already custom columns; `owner_email`
  stays in the virtual `data`). No new tenant table required — `notes` (foundation)
  is the proof-of-context table; the seed inserts demo notes.

## 9. TEST LIST (falsifiable)

Feature (real PG where DB-per-tenant is exercised; central-only tests may use the
configured test connection):

1. `CentralRegistrationTest` (extend): happy path → 302 redirect to
   `central.provisioning` with the new tenant id; tenant row `Pending`; domain row
   created; pipeline dispatched (`Bus`/`Event` faked + asserted).
2. Reserved subdomain → 302 + `assertSessionHasErrors('subdomain')`, 0 tenants
   (each reserved value).
3. Invalid format (short / long / uppercase / illegal char) → 302 + session error,
   0 tenants.
4. Duplicate subdomain → 302 + session error; exactly one tenant owns the host.
5. `ProvisioningPipelineTest`: drained synchronously (outside a transaction, mirror
   the isolation test) → tenant DB exists, `notes` table exists, seed rows present,
   status flipped to `Active`. (Alt: queue-faked → assert the pipeline jobs +
   the activation step were dispatched.)
6. `TenantIdentificationTest`: request the landing host for a provisioned tenant →
   200, Inertia `Tenant/Landing`, `notes_count` matches the tenant DB; central
   context sees a different/empty count.
7. `CrossTenantIsolationTest` (extend or add): any new tenant-owned write is isolated
   A↔B via Eloquent AND raw; distinct physical DBs.
8. Prop-contract tests: `Central/Register`, `Central/Provisioning`,
   `Tenant/Landing` assert exact prop keys/shape (`assertInertia`).
9. `TenantPlanTest` (Unit): `priceCents()` per case; cents are integers.
10. Lang resolution test (Feature): every new key resolves in `es` + `en`.
11. Arch tests stay green (no central↛tenant coupling, strict types, final Actions,
    backed enums).

## 10. OPEN QUESTIONS

- Status-page UX: poll-on-load reload is sufficient for MVP (no websockets). If the
  Biblia later mandates live push, add broadcasting in a follow-up slice.
- Enterprise price display: shown as "Contact sales" (price_cents = 0) — confirm copy.
