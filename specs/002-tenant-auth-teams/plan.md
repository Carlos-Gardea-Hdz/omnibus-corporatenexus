# Plan 002 — Tenant Auth + Teams/Members (the HOW)

- **Spec:** `specs/002-tenant-auth-teams/spec.md`
- **Gate:** CLAUDE.md § No-negociables + AGENTS §5 (strict types, DDD-Lite + Actions,
  Spatie Data SSOT, backed enums, reversible migrations, Pest, PHPStan 9, props
  snake_case, ES/EN, no PII). All satisfied below.

## A. Architecture & context

- **New domain:** `app/Domain/Membership` (tenant-context aggregate: members + roles).
  Reuse `app/Domain/Tenancy` for the central registry + lifecycle (unchanged except
  the seeder owner-seeding hook + a temp-password field on the central `data` column).
- **Two contexts:** auth + member management run **only** in tenant context (the
  tenant route group, behind `InitializeTenancyByDomain` +
  `PreventAccessFromCentralDomains`). The `web` guard's session lives in the tenant
  `sessions` table (slice-001 migration) because the DB connection is swapped to the
  tenant DB by `DatabaseTenancyBootstrapper`. **No new guard needed** — the default
  `web` guard authenticating against `App\Models\User` automatically resolves against
  the tenant DB once tenancy is initialized. This is the load-bearing mechanism for
  cross-tenant auth isolation.
- **Owner seeding bridge:** `TenantDatabaseSeeder` runs via stancl `tenants:seed`
  inside `tenancy()->runForMultiple`, so `tenant()` resolves the current tenant **with
  its central `data`** (incl. `owner_email`). The seeder reads `tenant()->owner_email`,
  generates a temp password, creates the owner `User`, and writes the temp password
  back to the central registry `data` (`owner_temp_password`) so the provisioning-status
  page can show it once.

## B. Data model / migrations (all in `database/migrations/tenant`, reversible)

1. **`2026_06_2x_..._add_role_to_users_table.php`** (tenant):
   - `up`: `$table->string('role')->default('member')->after('email');` (string column
     cast to `MemberRole`; default keeps existing rows valid).
   - `down`: `$table->dropColumn('role');`
   - **Note:** do NOT alter the `users` PK (stays `bigint id` from slice 001 — spec
     §10 open question accepted). Add an index only if needed; `email` is already
     unique per-tenant DB.
   - **Teams table is DEFERRED** (spec §3): no `teams`/`team_user` this slice.

2. **No central migration.** The owner temp password rides the existing virtual `data`
   column on `tenants` (`owner_temp_password`), like `owner_email` already does
   (`CreateTenant` writes `owner_email` to `data`). It is **never** a custom column and
   **never** propped except the one-time provisioning-status reveal.

## C. Enum — `app/Domain/Membership/Enums/MemberRole.php`

`final` backed `enum MemberRole: string` (`#[TypeScript]`), mirroring the CMS
`UserRole` ladder, adapted to 3 levels:

- cases: `Owner = 'owner'`, `Admin = 'admin'`, `Member = 'member'`.
- `level(): int` → owner 3, admin 2, member 1.
- `label(): string` → `__('members.role.'.$this->value)` (bilingual).
- `hasAtLeast(self $min): bool` → `$this->level() >= $min->level()`.
- `canManageMembers(): bool` → `$this->hasAtLeast(self::Admin)` (owner|admin).
- `canAssign(self $target): bool` → owner may assign any; admin may assign
  `admin`/`member` but **not** `owner`; member may assign none.
- Cast in `User::casts()` as `'role' => MemberRole::class`.

## D. Model — `app/Models/User.php` (extend, stays tenant model)

- Add to `$fillable`: `'role'`.
- `casts()`: add `'role' => MemberRole::class` (keep `'password' => 'hashed'`).
- `@property` PHPDoc block: `int $id`, `string $name`, `string $email`,
  `MemberRole $role`, `string $password` (hidden), `Carbon|null $email_verified_at`,
  timestamps.
- `protected static function newFactory(): UserFactory` (already references
  `UserFactory`; add the method so factory wiring is explicit, matching `Note`).
- `UserFactory`: add `'role' => MemberRole::Member` default + states `->owner()`,
  `->admin()` setting the role. Keep `password` via `Hash::make`.

## E. DTOs (Spatie Data, `#[TypeScript]`, validation SSOT)

1. **`app/Domain/Membership/Data/LoginData.php`** (mirror CMS `LoginData`, key on
   `email`):
   - `#[Required, Email, Max(255)] public string $email`,
   - `#[Required, Max(255)] public string $password`,
   - `public bool $remember = false`.

2. **`app/Domain/Membership/Data/InviteMemberData.php`**:
   - `#[Min(2), Max(120)] public string $name`,
   - `#[Email, Max(255)] public string $email`,
   - `public MemberRole $role = MemberRole::Member`.
   - `rules()`: `email` → `unique` on the **tenant** `users` table
     (`Rule::unique('users', 'email')` — resolves against the tenant connection in
     context); `role` → `Rule::enum(MemberRole::class)` (the controller/Action further
     restricts to the actor's assignable set — defence in depth).

3. **`app/Domain/Membership/Data/UpdateMemberRoleData.php`**:
   - `public MemberRole $role` (the only mutable field this slice; name/email edits
     deferred to keep scope tight — note in spec if asked).

4. **`app/Domain/Membership/Data/MemberData.php`** (output): `id:int`, `name`,
   `email`, `role: MemberRole`, plus `fromModel(User $user, User $actor): self` (sets
   `is_self`, `is_owner` view flags). NEVER includes password/token. Keep the
   `is_self`/`is_owner` in the controller mapper if Spatie lazy props complicate the
   contract — the spec §7.3 shape is the contract of truth.

## F. Actions (`app/Domain/Membership/Actions`, `final`, HTTP-agnostic)

1. **`AuthenticateMember`** (mirror CMS `AuthenticateUserAction`, key on `email`):
   - `handle(LoginData $data): User`. `Auth::attempt(['email'=>strtolower(trim()),
     'password'=>...], $remember)`. On fail → `ValidationException::withMessages(['email'
     => __('auth.failed')])` (uniform, non-enumerating). Returns the authed `User`.
   - Session regeneration + redirect are the **controller's** job (not the Action).
   - `Auth` facade allowed here (mirrors CMS); `Illuminate\Http` is NOT imported.

2. **`CreateMember`**:
   - `handle(InviteMemberData $data, User $actor): array{user: User, temp_password:
     string}` (or a small `MemberInvited` VO — prefer a `final readonly` VO
     `app/Domain/Membership/ValueObjects/MemberInvited.php` holding `User $user` +
     `string $tempPassword` to avoid a structured array, per law §3).
   - **Seat-limit check FIRST** (default-deny): read the tenant's plan via
     `tenant()->plan` (central data, available in tenant context because the Tenant
     model retains its central attributes) → `$limit = $plan->seatLimit()`. If
     `$limit > 0 && User::query()->count() >= $limit` → throw
     `SeatLimitExceededException` (`members.error.seat_limit`). `0` = unlimited (skip).
   - **Assignable-role guard:** `abort`/throw if `! $actor->role->canAssign($data->role)`
     (an admin cannot invite an `owner`) → `RoleNotAssignableException`.
   - `DB::transaction` (multi-row safety / count race): generate temp password
     (`Str::password(16)`), create the `User` (`password` set raw → hashed by the cast,
     never logged), return the VO. The plaintext temp password is returned ONCE to the
     caller for the flash; never persisted in the tenant DB beyond the hash.

3. **`UpdateMemberRole`** (mirror CMS `UpdateUserAction`'s owner-singleton logic):
   - `handle(UpdateMemberRoleData $data, User $target, User $actor): User`.
   - **Owner own-record guard:** if `$target->role === Owner`: another actor may not
     edit it (only the owner edits the owner), and the sole owner may not demote itself
     via a plain update → `OwnerSingletonException` (`members.error.owner_protected`).
   - **Assignable guard:** `! $actor->role->canAssign($data->role)` → reject (admin
     cannot mint `owner`).
   - **Owner transfer (singleton swap):** if `$data->role === Owner && ! $target->is
     (current owner)`: in the SAME `DB::transaction`, demote the current owner to
     `admin`, then promote `$target` to `owner` — exactly one owner remains.
   - Otherwise update the role in a single statement.

4. **`RemoveMember`** (mirror CMS `DeleteUserAction`):
   - `handle(User $target, User $actor): void`.
   - **Self-removal guard:** `$target->is($actor)` → `CannotRemoveSelfException`
     (`members.error.cannot_remove_self`).
   - **Owner-singleton guard:** target is the sole owner (`role === Owner` && no other
     owner) → `CannotRemoveOwnerException` (`members.error.cannot_remove_owner`).
   - Otherwise `$target->delete()` (hard delete — tenant users have no FK dependents
     this slice; if a later slice adds authored content, switch to SoftDeletes then).

**Exceptions:** `app/Domain/Membership/Exceptions/` — `SeatLimitExceededException`,
`RoleNotAssignableException`, `OwnerSingletonException`, `CannotRemoveSelfException`,
`CannotRemoveOwnerException`, each a `final` `RuntimeException` carrying a translated
message + the field name (`role` / `email` / null) so the controller renders a 302 +
field error / flash, never a 500.

## G. Controllers + routes (tenant route group only)

### Controllers (`app/Http/Controllers/Tenant`, anemic ≤15 lines/method, `final`)

1. **`Auth/LoginController`** (mirror CMS `LoginController`):
   - `create(): Response` → `Inertia::render('Auth/Login', ['tenant' =>
     TenantData::fromModel(tenant())])`.
   - `store(LoginData $data, AuthenticateMember $auth): RedirectResponse` →
     `$auth->handle($data)`; `request()->session()->regenerate()`;
     `redirect()->intended(route('tenant.dashboard'))`.
   - `destroy(): RedirectResponse` → `Auth::guard('web')->logout()`; invalidate +
     regenerate token; `redirect()->route('tenant.login')`.

2. **`DashboardController`** (exists — extend to add `auth_user`, `member_count`,
   `seat_limit`): render `Tenant/Dashboard` per §7.2 (build `auth_user` from
   `request()->user()`, `member_count = User::count()`, `seat_limit =
   tenant()->plan->seatLimit()`). **Add the missing `tenant.dashboard` route.**

3. **`MemberController`** (mirror CMS `Admin/UserController`, adapted):
   - `index(): Response` → roster mapped to §7.3, `can`/`assignable_roles`/seat math.
   - `store(InviteMemberData $data, CreateMember $action): RedirectResponse` →
     gate `manage` (owner|admin) via a private `actor()` + `abort_unless`; call action;
     redirect with success flash carrying the one-time temp password.
   - `update(User $user, UpdateMemberRoleData $data, UpdateMemberRole $action)` →
     gate manage; call action; redirect with success.
   - `destroy(User $user, RemoveMember $action)` → gate manage; call action; redirect.
   - Private `actor(): User` (`abort_unless(request()->user() instanceof User, 403)`),
     `assertCanManage(User $actor)` (`abort_unless($actor->role->canManageMembers(),
     403)`), `assignableRoles(User $actor)`, `mapMember(User, User $actor)`.
   - Domain exceptions → caught and rendered as `back()->withErrors([...])` /
     `->with('error', ...)` (a small `rescue`/try-catch in the controller, OR register
     them in `bootstrap/app.php` `withExceptions` to render 302 + flash — prefer the
     latter for DRY, mirroring how the CMS surfaces its identity exceptions).

### Routes — `routes/tenant.php` (restructure, still inside the tenancy group)

```php
Route::middleware(['web', InitializeTenancyByDomain::class,
    PreventAccessFromCentralDomains::class])->group(function (): void {

    Route::get('/', [LandingController::class, 'index'])->name('tenant.landing');

    // Guest-only (already-authed → dashboard)
    Route::middleware('guest')->group(function (): void {
        Route::get('/login', [LoginController::class, 'create'])->name('tenant.login');
        Route::post('/login', [LoginController::class, 'store'])
            ->middleware('throttle:6,1')->name('tenant.login.store');
    });

    // Auth-gated tenant app
    Route::middleware('auth')->group(function (): void {
        Route::post('/logout', [LoginController::class, 'destroy'])->name('tenant.logout');
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('tenant.dashboard');

        Route::get('/members', [MemberController::class, 'index'])->name('tenant.members.index');
        Route::post('/members', [MemberController::class, 'store'])->name('tenant.members.store');
        Route::patch('/members/{user}', [MemberController::class, 'update'])->name('tenant.members.update');
        Route::delete('/members/{user}', [MemberController::class, 'destroy'])->name('tenant.members.destroy');
    });
});
```
- `auth`/`guest` middleware use the default `web` guard → tenant DB session/users.
  `redirectGuestsTo` resolves to `tenant.login` (configure via the `auth` middleware /
  a tenant-aware redirect in `bootstrap/app.php` `$middleware->redirectGuestsTo(...)`
  pointing at `route('tenant.login')` — verify it resolves under the tenant host).
- `{user}` route-model binding resolves on the tenant connection (in context), so it
  cannot bind a cross-tenant user (the row simply isn't in this tenant's DB → 404).

## H. Owner seeding wiring (`TenantDatabaseSeeder`)

- After the demo notes, seed the owner:
  ```php
  /** @var \App\Domain\Tenancy\Models\Tenant $tenant */
  $tenant = tenant();
  $email = (string) $tenant->owner_email;        // from central data
  $tempPassword = Str::password(16);
  User::create([
      'name' => 'Owner',
      'email' => $email,
      'password' => $tempPassword,                // hashed by cast
      'role' => MemberRole::Owner,
      'email_verified_at' => now(),
  ]);
  // Persist the one-time temp password on the CENTRAL registry data column so the
  // provisioning-status page can reveal it once. Write on the central connection.
  $tenant->update(['owner_temp_password' => $tempPassword]);  // virtual data column
  ```
- **Caveat to verify in implementation:** `tenant()->update(...)` inside tenant context
  writes to the central registry (the Tenant model is bound to the central connection
  via the model, not the default connection) — confirm it persists to `tenants.data`
  and is readable by `ProvisioningStatusController` afterward. If the connection swap
  interferes, write via `Tenant::on(central_connection)->whereKey(...)->...` explicitly.
- `ProvisioningStatusController` (slice 001) gains a sibling prop `owner_temp_password:
  string|null` (only while present; clear-after-read is a nice-to-have, deferred) so
  the `Central/Provisioning` page can show the owner credential once. **Note:** this is
  the single intentional place a credential reaches a prop — guarded by the spec §7
  PII rule everywhere else. (If product prefers NOT to surface it centrally, fall back
  to the deferred set-password flow — spec §10.)

## I. Frontend (Inertia/React/TS)

- Pages: `resources/js/Pages/Auth/Login.tsx` (email/password form via Spatie-Data
  `useForm` shape `LoginData`), extend `Tenant/Dashboard.tsx` (auth_user, counts),
  new `Tenant/Members/Index.tsx` (roster table + invite form + role select + remove,
  all gated by the `can.*` props; temp-password shown from flash).
- Dark/light + ES/EN i18n via the existing `lib/i18n.tsx` + `lib/theme.tsx`.
- `php artisan typescript:transform` regenerates `MemberRole`, `LoginData`,
  `InviteMemberData`, `UpdateMemberRoleData`, `MemberData` into
  `resources/js/types/generated.d.ts` (types only; type-only import).

## J. Lang keys (both `es` + `en`)

- `lang/{es,en}/auth.php`: `failed` (generic credential error), plus
  `login.title`, `login.email`, `login.password`, `login.submit`, `logout`.
- `lang/{es,en}/members.php`:
  - `role.owner|admin|member`,
  - `title`, `invite`, `invite.name|email|role|submit`, `remove`, `update_role`,
  - `created`, `updated`, `removed` (success flashes),
  - `seat.unlimited`, `seat.remaining`, `seat.used`,
  - `error.seat_limit`, `error.owner_protected`, `error.cannot_remove_owner`,
    `error.cannot_remove_self`, `error.role_not_assignable`.
- React i18n dictionary: mirror every new key for both locales. A Feature
  lang-resolution test asserts parity (extend slice-001 `LangResolutionTest`).

## K. Arch tests (extend `tests/Unit/ArchTest.php`)

- `App\Domain\Membership` is HTTP-agnostic (`not->toUse('Illuminate\Http')`).
- `App\Domain\Membership\Actions` final + classes; `App\Domain\Membership\Enums`
  backed; `App\Domain\Membership\Data` final.
- `App\Http\Controllers\Central` `not->toUse('App\Models\User')` (central never
  touches the tenant user model — extends the existing Note guard).
- Tenant member controllers live under `App\Http\Controllers\Tenant`.

## L. Files to create / touch (inventory)

**Create:**
- `database/migrations/tenant/2026_06_2x_add_role_to_users_table.php`
- `app/Domain/Membership/Enums/MemberRole.php`
- `app/Domain/Membership/Data/{LoginData,InviteMemberData,UpdateMemberRoleData,MemberData}.php`
- `app/Domain/Membership/ValueObjects/MemberInvited.php`
- `app/Domain/Membership/Actions/{AuthenticateMember,CreateMember,UpdateMemberRole,RemoveMember}.php`
- `app/Domain/Membership/Exceptions/{SeatLimitExceeded,RoleNotAssignable,OwnerSingleton,CannotRemoveSelf,CannotRemoveOwner}Exception.php`
- `app/Http/Controllers/Tenant/Auth/LoginController.php`
- `app/Http/Controllers/Tenant/MemberController.php`
- `resources/js/Pages/Auth/Login.tsx`, `resources/js/Pages/Tenant/Members/Index.tsx`
- `lang/{es,en}/auth.php`, `lang/{es,en}/members.php`
- Tests: `tests/Feature/Tenancy/TenantAuthTest.php`,
  `tests/Feature/Tenancy/CrossTenantAuthIsolationTest.php`,
  `tests/Feature/Tenancy/OwnerProvisioningTest.php`,
  `tests/Feature/Tenancy/MemberManagementTest.php`,
  `tests/Feature/Tenancy/MemberPropContractTest.php`,
  `tests/Unit/Membership/MemberRoleTest.php`.

**Touch:**
- `app/Models/User.php` (role fillable/cast/@property/newFactory),
  `database/factories/UserFactory.php` (role + states).
- `routes/tenant.php` (guest/auth groups), `app/Http/Controllers/Tenant/DashboardController.php`
  (extended props), `database/seeders/TenantDatabaseSeeder.php` (owner seed).
- `app/Http/Controllers/Central/ProvisioningStatusController.php` +
  `resources/js/Pages/Central/Provisioning.tsx` (one-time owner temp-password reveal).
- `bootstrap/app.php` (`redirectGuestsTo(route('tenant.login'))` + optional domain
  exception → 302 renders).
- `tests/Unit/ArchTest.php`, `tests/Feature/Tenancy/{PropContractTest,LangResolutionTest}.php`
  (extend), React i18n dictionary, `resources/js/Pages/Tenant/Dashboard.tsx`.

## M. Quality gates (verify, do NOT run pest/migrate on the shared DB)

- After generation: `php artisan typescript:transform`, `composer analyse` (PHPStan 9),
  `tsc --noEmit`, `composer format` (Pint). Do NOT run `pest`/`migrate` locally
  (shared DB). The test list (§9 spec) is the falsifiable contract for CI on real PG18.

## N. Risks / verify-during-implementation

1. **`auth`/`guest` redirect target under the tenant host** — confirm
   `redirectGuestsTo` yields the tenant login URL on the subdomain (not the central
   host). Test S3 is the falsifier.
2. **Owner temp-password central write from inside tenant context** (§H caveat) — verify
   `tenant()->update()` lands on `tenants.data` and survives the context. Fallback:
   explicit `Tenant::on(central)`.
3. **`Rule::unique('users','email')` in `InviteMemberData`** resolves on the tenant
   connection while tenancy is initialized — confirm the validator uses the swapped
   default connection (it should, as the request is in tenant context). Falsifier: the
   invite test on a real provisioned tenant.
4. **Session table** — slice-001 tenant migration already has `sessions`; confirm
   `SESSION_DRIVER` is `database` (or that the chosen driver is tenant-scoped) so
   sessions are isolated per tenant. If `SESSION_DRIVER=cookie`, sessions are still
   per-domain (subdomain) — acceptable, but DB driver is the spec's intent for
   isolation; note/confirm in implementation.
```
