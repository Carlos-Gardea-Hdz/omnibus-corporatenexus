# Spec 002 — Tenant-side Auth + Teams/Members (tenant DB context)

- **Status:** Draft (architect contract)
- **Domain:** `app/Domain/Membership` (new, tenant-context) + reuse `app/Domain/Tenancy`
- **Engine:** stancl/tenancy v3 (ADR-001), DB-per-tenant on PostgreSQL 18
- **Author:** Architect agent — 2026-06-20
- **Grounding:** Biblia §4.5 (tenant app: users, teams/members, roles, seat limits,
  auth model); ADR-001; slice 001 (`specs/001-tenant-lifecycle/spec.md`, the tenant
  lifecycle, `TenancyServiceProvider`, the tenant migrations, `TenantDatabaseSeeder`,
  `config/tenancy.php`); the CMS Identity slice 001
  (`/home/carlos/coding/omnibus-cms/app/Domain/Identity`) as the auth/Action/DTO +
  singleton-owner reference, **adapted to run INSIDE the tenant DB**.

## 1. WHAT & WHY

Slice 001 delivered the tenant **lifecycle** and a **public** tenant landing. This
slice makes the tenant app **private and multi-user**:

1. **Tenant-context auth.** Tenant users live in the **tenant** database (the
   `users` table already exists under `database/migrations/tenant`). Login / logout
   / session happen **after** tenant identification, on the tenant subdomain; the
   `web` session guard authenticates against the **tenant** `users` table. The
   central domain can NEVER reach tenant auth. A user of tenant A can NEVER
   authenticate on tenant B (per-tenant DB guarantees it).
2. **Owner seeding.** Provisioning (slice 001) already records the org's
   `owner_email` in the central registry. This slice seeds the **first user (the
   owner, role `owner`)** into the tenant DB during provisioning, and surfaces a
   one-time temporary password on the central provisioning-status page (email
   delivery is deferred).
3. **Tenant dashboard behind auth.** A `Tenant/Dashboard` page requires tenant auth.
4. **Teams/Members.** Inside a tenant, manage members: list / invite / update-role /
   remove. Seat-limited per `TenantPlan->seatLimit()` (0 = unlimited); adding past
   the limit is rejected (**302 + error**). `MemberRole` gates govern who may do
   what. The owner is a **singleton** — cannot be removed or demoted to leave the
   tenant ownerless (adapted from the CMS super_admin rule).

**Why this shape:** it proves the hardest multitenancy auth properties (session
isolation, cross-tenant auth impossibility) on the smallest real surface, and
establishes the member/role/seat-limit spine every later tenant feature builds on.

## 2. IN SCOPE

- **Tenant auth:** login (`GET`/`POST`), logout (`POST`) on the tenant route group;
  a `MemberRole` backed enum (`owner` > `admin` > `member`); the tenant `users`
  table extended with a `role` column (tenant migration, reversible); the `User`
  model gains `role` cast + `MemberRole` helpers + `@property` PHPDoc + `newFactory`.
- **Owner provisioning:** `TenantDatabaseSeeder` seeds the owner user from
  `tenant()` central data (`owner_email`) with a generated temp password; the temp
  password is captured on the central registry (`data`) and shown once on the
  provisioning-status page.
- **Members domain (`app/Domain/Membership`):** DTOs (`InviteMemberData`,
  `UpdateMemberRoleData`), Actions (`CreateMember`, `UpdateMemberRole`,
  `RemoveMember`) with the **seat-limit check** and the **owner-singleton guard**,
  a `MemberData` output DTO, role-gated `MemberController` (index/store/update/destroy)
  on the tenant route group.
- A tenant `RedirectIfAuthenticated` / `Authenticate` entry: tenant routes split
  into guest (login) and `auth`-gated (dashboard, members) middleware groups, all
  **inside** the tenant identification + `PreventAccessFromCentralDomains` group.
- Bilingual lang keys (ES/EN) for every new user-facing string in both PHP lang
  files (`lang/{es,en}/auth.php`, `lang/{es,en}/members.php`) **and** the React i18n
  dictionary, with a resolution test.
- The exact Pest test list in §9.

## 3. DEFERRED (note, do not build)

- **Central platform-admin auth** — the central domain stays public this slice
  (registration + provisioning-status only). A separate central guard (a distinct
  `admin` guard against a central `platform_admins` table), and gating Horizon/Pulse
  behind it, is a later slice. Note only.
- **MFA / passkeys / WebAuthn** — security §8 hardening; later slice.
- **Email-delivered invitations** — `CreateMember` provisions the member row +
  temp password **synchronously**; no mailer, no signed accept-link, no
  email-verification flow. The temp password is returned to the inviter via flash
  (shown once). Real invitation emails + accept flow are deferred.
- **Password reset / "forgot password"** — the `password_reset_tokens` table exists
  (slice 001) but no reset flow is wired; later slice.
- **Self-service registration of tenant users** — only an owner/admin adds members
  (no public tenant-user signup).
- **Teams as a sub-grouping inside a tenant** — the Biblia "teams/members" maps, for
  this slice, to **the tenant itself as the team** and its members. A `teams` table
  (multiple teams per tenant) is **deferred**; this slice ships `users` + `role`
  only. Note: if a later slice needs many teams per tenant, add a `teams` +
  `team_user` pivot tenant migration then.
- **Plan upgrades changing the seat limit live** — seat limit is read from the
  current `TenantPlan`; no in-app upgrade path.

## 4. ACTORS

- **Owner (tenant):** seeded at provisioning; full member management; cannot be
  removed or demoted while sole owner.
- **Admin (tenant):** may add/remove/update members up to `member` and `admin`;
  may NOT remove/demote the owner; may not mint a second owner unless the
  owner-transfer path is invoked (see §5 S-OWNER).
- **Member (tenant):** read-only on the members screen (sees the roster; no manage
  controls).
- **Visitor (central):** unchanged (registration + provisioning status).

## 5. ACCEPTANCE SCENARIOS (When … Then)

**S1 — Tenant login happy path.**
When a provisioned tenant's owner POSTs valid `{email, password}` to the tenant
login route on `{subdomain}.{central_domain}`, Then the session guard authenticates
against the **tenant** `users` table, the session is regenerated, and the response is
a **302** to the tenant dashboard.

**S2 — Tenant login rejects bad credentials (no 422, no enumeration).**
When the email or password is wrong, Then the response is **302 + a session error on
`email`** (a single generic credential message), never 422, and the user stays
unauthenticated.

**S3 — Auth-gated tenant route requires tenant auth.**
When an unauthenticated request hits the tenant dashboard or members route, Then the
response is a **302** to the tenant login route; an authenticated request gets **200**.

**S4 — Login is impossible from the central domain.**
When a login is attempted on the central domain (the apex host), Then it does **not**
reach tenant auth (no tenant login route exists on the central domain → 404/redirect);
tenant auth is only reachable after tenant identification.

**S5 — CROSS-TENANT auth isolation (the crown).**
Given a user seeded in tenant A with a known password, When that exact
`{email, password}` is submitted on tenant B's login route, Then authentication
**fails** (B's `users` table has no such row) — proven for both a happy A-login and a
failed B-login with identical credentials. A's session never authorizes B.

**S6 — Owner is seeded at provisioning.**
When a tenant is provisioned (pipeline drained), Then the tenant `users` table
contains exactly one row whose `email` equals the central `owner_email` and whose
`role` is `owner`, with a hashed (non-plaintext) password, and the owner can log in
with the surfaced temp password.

**S7 — Members list is role-aware.**
When the owner or an admin views the members page, Then they receive the member
roster plus `can` manage flags = true; when a `member` views it, Then `can` manage
flags = false (read-only).

**S8 — Invite a member (happy path).**
When an owner/admin POSTs a valid `{name, email, role∈{admin,member}}` and the tenant
is under its seat limit, Then a tenant `users` row is created with the chosen role and
a hashed temp password, and the response is **302 + success flash** (temp password
shown once).

**S9 — Seat limit rejection.**
Given a tenant on a plan with `seatLimit() = N` (N > 0) already holding N members,
When an owner/admin invites another member, Then **no** row is created and the
response is **302 + a session/flash error** (seat-limit message). Given a plan with
`seatLimit() = 0` (unlimited, e.g. Enterprise), inviting never trips the limit.

**S10 — Role gating on member management.**
When a `member` (read-only) POSTs to store/update/destroy, Then **403**. When an
`admin` tries to assign or target the `owner` role, Then it is rejected (admins
cannot mint/move ownership) — **302 + error** or 403 per §8.

**S11 — Owner-singleton guard (no removal).**
When any actor attempts to remove the **sole** owner, Then it is rejected (**302 +
error**) and the owner row remains.

**S12 — Owner-singleton guard (no demotion).**
When the sole owner is demoted to `admin`/`member` via a plain update, Then it is
rejected (**302 + error**); the only way ownership changes is the explicit
owner-transfer (promote another member to `owner`), which demotes the previous owner
to `admin` in the **same transaction** so exactly one owner always exists.

**S13 — Self-removal guard.**
When an actor attempts to remove **themselves**, Then it is rejected (**302 +
error**) — no actor locks itself out mid-session.

**S14 — Cross-tenant data isolation for member rows.**
When tenant A creates a member, Then tenant B cannot read/update/delete that row via
Eloquent **or** raw query, and A/B resolve to distinct physical databases (extends
the slice-001 isolation suite to the `users` table).

**S15 — Prop contracts.**
`Auth/Login`, `Tenant/Dashboard`, and `Tenant/Members/Index` each receive exactly the
snake_case prop shape in §7 (contract test per page).

**S16 — Lang resolution.**
Every new `__()` key resolves in both `es` and `en`; every new React i18n key exists
for both locales.

## 6. NON-FUNCTIONAL / LAWS

- `declare(strict_types=1)`, `final`, explicit return types; `readonly` VO/DTO.
- Backed enums with helpers; **no magic strings**. `MemberRole` is a backed enum.
- UUIDv7 for any new id we mint (tenant `users` keep the existing `bigint id` — see
  plan §note; do NOT change the PK type to avoid churning slice-001 migrations).
- Web validation → **302 + session errors**, never 422 (Spatie Data via controller
  signature). Auth failures → uniform non-enumerating `ValidationException` on `email`.
- Inertia props **snake_case**; generated enum `.d.ts` is **types only**
  (type-only import). Password is **never** propped, never logged.
- Auth/Session facades may be used **in controllers** (the CMS pattern); the Domain
  layer (`app/Domain/Membership/*`) must NOT import `Illuminate\Http`. `Auth`/`Session`
  facade use inside an **Action** is allowed only where it mirrors the CMS
  `AuthenticateUserAction` (the auth Action), kept out of `Illuminate\Http`.
- Central context must not query tenant `users`; tenant auth runs only in tenant
  context. The arch test `central controllers do not touch per-tenant models`
  extends to the tenant `User` (+ any new tenant model).
- Migrations reversible; **all** new tenant tables/columns in
  `database/migrations/tenant`. No central schema change (owner temp password rides
  the existing virtual `data` column).
- Money/seats as **int**. Seat limit comparison uses `TenantPlan->seatLimit()`.
- PHPStan **level 9** (match repo), Pint clean, `typescript:transform` regenerated,
  `tsc --noEmit` clean. DB/`__()` tests live in `Feature`, never `Unit`.
- Models need `newFactory()` + `@property` PHPDoc (non-nullable `belongsTo` not
  `|null`). No real PII — fictional members only.

## 7. INERTIA PROP CONTRACTS (snake_case)

### 7.1 `Auth/Login` (GET tenant `/login`)
```
{
  tenant: { id: string, name: string, status: string, plan: string }  // TenantData
}
```
(No secrets; just enough to brand the login with the org name.)

### 7.2 `Tenant/Dashboard` (GET tenant `/dashboard`, auth)
```
{
  tenant: { id: string, name: string, status: string, plan: string }, // TenantData
  auth_user: {
    id: number,
    name: string,
    email: string,
    role: string                 // MemberRole->value
  },
  member_count: number,          // live COUNT of tenant users
  seat_limit: number             // TenantPlan->seatLimit() (0 = unlimited)
}
```

### 7.3 `Tenant/Members/Index` (GET tenant `/members`, auth)
```
{
  members: Array<{
    id: number,
    name: string,
    email: string,
    role: string,                // MemberRole->value
    role_label: string,          // bilingual via ->label()
    is_self: boolean,            // row === acting user
    is_owner: boolean            // role === owner
  }>,
  seat_limit: number,            // 0 = unlimited
  seat_used: number,             // current member count
  seats_remaining: number | null,// null when unlimited, else max(limit - used, 0)
  assignable_roles: Array<{ value: string, label: string }>, // roles the actor may assign
  can: {
    manage_members: boolean,     // owner|admin
    invite: boolean,             // manage && under seat limit
    transfer_ownership: boolean  // owner only
  }
}
```
**PII/secret guard:** no `password`, no `remember_token`, no temp password in any
member prop. The one-time temp password from invite/provisioning is delivered ONLY
via a flash message, never a persisted prop.

## 8. ROLE GATING MATRIX (server-side, falsifiable)

| Operation                         | owner | admin | member |
|-----------------------------------|:-----:|:-----:|:------:|
| View members (read)               |  ✅   |  ✅   |   ✅   |
| Invite member (role ≤ admin)      |  ✅   |  ✅   |   ❌(403) |
| Update member role (≤ admin)      |  ✅   |  ✅   |   ❌(403) |
| Assign/transfer `owner` role      |  ✅   |  ❌   |   ❌   |
| Remove member                     |  ✅   |  ✅   |   ❌(403) |
| Remove/demote the sole owner      |  ❌(302+err) | ❌ | ❌ |
| Remove self                       |  ❌(302+err) | ❌(302+err) | ❌(403 before reaching guard) |

- The route group is gated `auth` (tenant guard). Write routes additionally require
  `manage members` authority (owner|admin) — a `member` hitting store/update/destroy
  is **403** (gate at controller boundary). The owner-singleton, owner-assignment,
  and self-removal rules are **domain guards** in the Actions (graceful 302 + error,
  never 500). MemberRole ladder: `owner(3) > admin(2) > member(1)`.

## 9. TEST LIST (falsifiable)

Feature (real PG where DB-per-tenant / tenant context is exercised; central-only
prop tests may use the configured test connection):

1. **`TenantAuthTest`** — login happy path (S1): provision a tenant, seed/known owner,
   `POST` tenant `/login` on the tenant host → 302 to dashboard, authenticated.
2. **`TenantAuthTest`** — bad credentials (S2): 302 + `assertSessionHasErrors('email')`,
   still a guest; never 422.
3. **`TenantAuthTest`** — auth gate (S3): guest → dashboard/members route → 302 to
   login; authed → 200.
4. **`TenantAuthTest`** — logout (S1 tail): authed `POST /logout` → 302 to login,
   session invalidated.
5. **`CrossTenantAuthIsolationTest`** (the crown, S5): seed user U in tenant A with
   password P; `POST {U.email, P}` on tenant A → authenticated; the **identical**
   `{U.email, P}` on tenant B → **fails** (guest, session error). Distinct physical
   DBs asserted.
6. **`OwnerProvisioningTest`** (S6): drain the provisioning pipeline (mirror
   `ProvisioningPipelineTest`: CreateDatabase → migrate → SeedDatabase →
   MarkTenantActive) → tenant `users` has exactly one row, `email == owner_email`,
   `role == owner`, password is hashed (not the plaintext), and a login with the
   surfaced temp password succeeds.
7. **`MemberManagementTest`** — invite happy path (S8): owner/admin invites valid
   member under the limit → row created with chosen role, hashed password, 302 +
   success flash.
8. **`MemberManagementTest`** — seat-limit rejection (S9): fill to `seatLimit()` on a
   limited plan → invite → 0 new rows, 302 + error; unlimited plan (seatLimit 0)
   never trips.
9. **`MemberManagementTest`** — role gating (S10): a `member` POSTing
   store/update/destroy → 403; an admin attempting to assign/target `owner` →
   rejected (302 + error).
10. **`MemberManagementTest`** — owner-singleton no-removal (S11): remove sole owner →
    302 + error, owner remains.
11. **`MemberManagementTest`** — owner-singleton no-demotion + transfer (S12): demote
    sole owner via plain update → 302 + error; promote another member to `owner` →
    the previous owner becomes `admin`, exactly one owner remains (same transaction).
12. **`MemberManagementTest`** — self-removal guard (S13): actor removes self → 302 +
    error.
13. **`CrossTenantIsolationTest`** (extend): a member row written in A is invisible to
    B via Eloquent **and** raw query (S14); distinct physical DBs.
14. **Prop-contract tests** (`PropContractTest` extend or `MemberPropContractTest`):
    `Auth/Login`, `Tenant/Dashboard`, `Tenant/Members/Index` assert exact prop
    keys/shape; assert NO `password`/`remember_token` leaks (`->missing(...)`).
15. **`MemberRoleTest`** (Unit): `level()` ladder, `label()` keys, `hasAtLeast()`,
    `canManage()`/assignable-set helpers; enum is backed (arch).
16. **Lang resolution test** (Feature): every new `auth.*` + `members.*` key resolves
    in `es` + `en`; every new React i18n key exists for both locales.
17. **Arch tests stay green**: strict types; `App\Domain\Membership` is
    HTTP-agnostic (no `Illuminate\Http`); Actions final; `MemberRole` backed; central
    controllers do not touch the tenant `User`.

## 10. OPEN QUESTIONS

- **Owner temp-password delivery:** MVP surfaces it ONCE on the central
  provisioning-status page (and the invite flash). Confirm copy + that this is
  acceptable until email delivery lands (deferred). Alternative: force a
  set-password-on-first-login flow — deferred to the password-reset slice.
- **Owner transfer UX:** included as a guarded path (promote → demote in one tx) but
  no dedicated screen this slice; it rides the update-role form (assigning `owner` is
  owner-only). Confirm whether a dedicated "transfer ownership" confirm step is wanted
  later.
- **`users.id` type:** kept as `bigint` (slice-001 migration) rather than UUIDv7, to
  avoid rewriting the foundation `users` migration + sessions FK. New mints elsewhere
  use UUIDv7. Confirm this is acceptable (the law's UUIDv7 rule targets *new* aggregate
  ids; the foundation users table predates this slice).
