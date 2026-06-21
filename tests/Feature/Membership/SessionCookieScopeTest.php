<?php

declare(strict_types=1);

/*
| Tenant session-cookie isolation (W1). A leading-dot SESSION_DOMAIN
| (`.nexus.localhost`) mints ONE cookie shared across every tenant subdomain, so
| a session minted on tenant A is replayed to B and the web guard re-resolves it
| to B's `users.id = N` — a DIFFERENT person (cross-tenant session reuse).
|
| The fix is a HOST-ONLY cookie: `SESSION_DOMAIN=null`, so the cookie is returned
| only to the exact subdomain that set it. We assert the resolved config is null
| (no wildcard) in the test environment — the minimal, in-process guarantee.
*/

it('mints a host-only session cookie (no leading-dot wildcard across tenant subdomains)', function (): void {
    // null domain → host-only cookie: the browser returns it ONLY to the exact
    // host that set it, so a tenant-A session is never sent to tenant B.
    expect(config('session.domain'))->toBeNull();
});
