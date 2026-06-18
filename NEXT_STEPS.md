# NEXT_STEPS — omnibus-corporatenexus

Foundation scaffolded 2026-06-18 (commit `648fc34`, 107 files). This file lists what was verified and what remains; build the domain out via `/sdd`.

## Verified at scaffold time

Verified end-to-end in containers/host, all green: (1) Pest — 17 tests / 48 assertions pass, including tenant provisioning via Action and HTTP endpoint, reserved-subdomain rejection (DTO + HTTP boundary), UUIDv7 id assertion, plan-scoped Pennant feature gating, and arch tests (strict_types everywhere, no debug helpers, final Actions, backed enums). (2) PHPStan/Larastan level 9 — No errors. (3) Pint --test — PASS, 57 files. (4) php artisan typescript:transform — transformed 5 PHP types to resources/js/types/generated.d.ts. (5) pnpm type-check (tsc --noEmit) — clean. (6) pnpm lint (ESLint + react-hooks) — clean. (7) pnpm build — client + SSR vite builds succeed with per-page code-splitting. (8) pnpm install --frozen-lockfile — passes supply-chain policy. (9) route:list confirms central/tenant routes resolve. (10) git working tree clean; verified .env, vendor, node_modules, public/build, bootstrap/ssr are NOT staged; no secrets in staged diff.

## Known gaps / issues

- Inertia client pinned to @inertiajs/react ^2 (resolved 2.3.26) to match the resolved inertiajs/inertia-laravel v2 server adapter, whereas the vault inertia-react-advanced.md targets the v3 client line. Server+client are versioned together; documented in ARCHITECTURE.md §8. React 19.2.1+ security pin is satisfied (resolved 19.2.7).
- Cross-tenant isolation tests (write-as-A / cannot-read-as-B through Eloquent AND raw query) required by multitenancy §5 are NOT yet written — the test DB is sqlite :memory: and exercises central-DB provisioning + Pennant only; real DB-per-tenant isolation tests need a Postgres test connection.
- RLS coverage CI assertion (multitenancy §2/§5) is not applicable yet since no shared-schema tenant tables exist (pure DB-per-tenant), but should be added if/when a shared-schema tier is introduced.
- phpstan analysis scope excludes config/ (stock Laravel config/filesystems.php triggers a known larastan false-positive on env() rtrim); app/database/routes are analysed at L9.
- @inertiajs/core had to be added as an explicit dependency so the v2 InertiaConfig module augmentation resolves for shared-prop typing.
- No CI workflow, Dockerfile, or compose file authored yet (sibling omnibus-cms has these); deployment/Octane(FrankenPHP) wiring is configured via env only.

## Next steps

- [ ] Add cross-tenant isolation tests against a real PostgreSQL test connection: create tenants A+B, write as A, assert B cannot read/update/delete via Eloquent AND raw DB queries; add an Octane context-bleed smoke test.
- [ ] Author Dockerfile + compose.yaml (Octane/FrankenPHP, PostgreSQL 18, Valkey 8) and a CI workflow running composer test/analyse/format, typescript:transform drift check, pnpm type-check/lint/build, composer audit + pnpm audit, and a secret scan (gitleaks).
- [ ] Wire authentication for tenant users (passkeys/MFA per security §8) and central platform-admin auth; gate any Horizon/Pulse/Telescope dashboards.
- [ ] Implement the tenant provisioning job pipeline (DB create + tenants:migrate + seed) as queued jobs and verify queued jobs restore tenant context; add a tenant seeder.
- [ ] Add a TenantConfig/Octane verification step before enabling FrankenPHP in prod (known stale-config bug noted in multitenancy §2).
- [ ] Run composer install / pnpm install on a fresh checkout to confirm reproducibility, then create the GitHub repo and push (deferred per instructions).
- [ ] Flip CSP from Report-Only to enforcing after collecting violation reports; configure SESSION_SECURE_COOKIE=true and HSTS preload staging in production.
