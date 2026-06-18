# CorporateNexus

Multi-tenant corporate SaaS hub. Part of the **OMNIBUS** program
(chapter 4.5). Production domain: `nexus.carlosgardea.com`.

Tenants are identified by subdomain (`acme.nexus.carlosgardea.com`) and isolated
with a **database-per-tenant** strategy via [`stancl/tenancy`](https://tenancyforlaravel.com).
Plan-based capabilities are gated per tenant with **Laravel Pennant**. The
frontend is **Inertia + React 19 + TypeScript**, with Spatie Laravel Data DTOs
as the single source of truth for validation and generated TypeScript types.

## Stack

| Layer        | Choice |
|--------------|--------|
| Language     | PHP 8.5 |
| Framework    | Laravel 12 |
| Multitenancy | `stancl/tenancy` v3.10 (DB-per-tenant, hybrid-capable) |
| Feature flags| Laravel Pennant (`database` driver, tenant-scoped) |
| Database     | PostgreSQL 18 (central + tenant DBs) |
| Cache/Queue  | Valkey 8 (Redis-compatible) |
| Frontend     | Inertia (React 19.2 + TypeScript 5.9) |
| Styling      | Tailwind CSS v4 (dark/light, WCAG 2.2 AA) |
| DTOs/Types   | Spatie Laravel Data v4 + TypeScript Transformer |
| Tests        | Pest 3 · PHPStan/Larastan level 9 · Pint |

## Architecture at a glance

- **DDD-Lite**: domain code in `app/Domain/Tenancy/{Models,Actions,Data,Enums,Events,Exceptions}`.
- **Central vs tenant split**: `routes/central.php` (registration, billing, admin)
  on the apex host; `routes/tenant.php` behind tenancy identification +
  `PreventAccessFromCentralDomains`.
- **Migrations split**: `database/migrations/` (central registry) and
  `database/migrations/tenant/` (run on every tenant DB).
- Full detail in [`ARCHITECTURE.md`](ARCHITECTURE.md).

## Getting started

Prerequisites: Docker (PHP/Composer run in the `composer:2` image), Node 22+ /
pnpm, PostgreSQL 18, Valkey 8.

```bash
# 1. Configure environment
cp .env.example .env
php artisan key:generate

# 2. Backend dependencies
composer install

# 3. Central database (tenant registry, billing, features)
php artisan migrate
php artisan db:seed            # fictional demo tenants

# 4. Provision tenant databases (runs database/migrations/tenant on each)
php artisan tenants:migrate

# 5. Frontend
pnpm install
pnpm run dev                   # or: pnpm run build
```

The central app is served on `CENTRAL_DOMAIN` (default `nexus.localhost`). Add
tenant subdomains to your hosts file for local work, e.g.
`127.0.0.1 acme.nexus.localhost`.

## Quality gates (run before every commit)

```bash
composer test       # Pest suite (incl. provisioning + arch tests)
composer analyse    # PHPStan / Larastan level 9
composer format     # Laravel Pint
composer types      # Regenerate TypeScript types from DTOs
pnpm run type-check # tsc --noEmit
pnpm run lint       # ESLint (react-hooks rules)
```

## Security notes

- Server-authoritative authorization on every request; tenant identity derived
  only from the identification middleware, never from client input.
- Baseline security headers + per-response CSP nonce (`SecurityHeaders`
  middleware); CSP shipped in report-only first.
- Sessions are server-side (Valkey) with `HttpOnly; Secure; SameSite`; the
  session cookie domain is the apex so it is shared across tenant subdomains.
- See `~/.claude/AGENTS.md` and `.agent-rules/{multitenancy,security-2026}.md`
  for the governing rules.
