# ADR-001 — Multi-tenancy engine: stancl/tenancy (not Spatie)

- **Status:** Accepted (2026-06-20)
- **Context:** CorporateNexus is a DB-per-tenant SaaS. The reference doc (Biblia §4.5)
  presents the multi-tenancy chapter using `spatie/laravel-multitenancy`. The
  scaffold and the hardened foundation were built on `stancl/tenancy` ^3.9.

## Decision

Use **`stancl/tenancy` v3** as the tenancy engine. The Biblia is a learning guide,
not a binding spec; the engine choice is reconciled here and the Biblia chapter
carries a pointer to this ADR.

## Rationale

- Both packages deliver the same security property — **physical database isolation
  per tenant** — which is the only hard requirement. This repo's
  `tests/Feature/Tenancy/CrossTenantIsolationTest.php` proves it on real PostgreSQL 18
  (tenant A's row is invisible/immutable to tenant B via Eloquent **and** raw SQL;
  the two tenants resolve to distinct physical databases).
- stancl v3 is the modern, actively-maintained, de-facto standard for DB-per-tenant
  Laravel. It is *batteries-included*: besides the database, it can tenant-scope the
  cache, queue, and filesystem, and ships multiple tenant-identification strategies
  (domain / subdomain / path / header) — useful headroom for a SaaS hub.
- Rewriting working, security-tested tenancy plumbing to match a guide would discard
  tested code and re-risk the isolation boundary for **zero functional gain**.

## Consequences

- The engine coupling lives in ~3 places: the `Tenant` model, the
  `TenancyServiceProvider` lifecycle wiring (TenancyInitialized→BootstrapTenancy,
  TenancyEnded→RevertToCentralContext, the TenantCreated/Deleted provisioning
  pipeline), and the central-vs-tenant connection config. Business domains
  (Billing, Teams, etc.) are **engine-agnostic** — they run inside whatever tenant
  connection is active — so a future migration to Spatie would touch only the
  plumbing, not the domains.
- Tenant provisioning (`CREATE DATABASE`) is **queued** — PostgreSQL forbids
  `CREATE DATABASE` inside a transaction, so a queue worker must drain the pipeline
  for a created tenant to leave `Pending` and get its database + migrations.
- The Biblia §4.5 code samples (Spatie API) are **illustrative only**; the running
  truth is this ADR + the code.

## Alternative considered

`spatie/laravel-multitenancy` (per the Biblia) — leaner and more explicit, equally
correct for DB-per-tenant. Rejected only because the stancl foundation already exists,
is tested green, and offers more SaaS-oriented features.
