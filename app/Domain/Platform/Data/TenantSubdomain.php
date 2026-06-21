<?php

declare(strict_types=1);

namespace App\Domain\Platform\Data;

use App\Domain\Tenancy\Models\Tenant;

/**
 * Derives a tenant's subdomain label from its primary central domain host,
 * stripping the central-domain suffix (e.g. "acme.nexus.localhost" → "acme").
 *
 * Central console helper (slice 003): reads only the already-loaded central
 * `domains` relation — never enters tenant context. Returns the full host as a
 * fallback when the suffix does not match, so the console always shows something
 * truthful rather than an empty string.
 */
final readonly class TenantSubdomain
{
    public static function fromTenant(Tenant $tenant): string
    {
        $domain = $tenant->domains->first();

        if ($domain === null) {
            return '';
        }

        $host = $domain->getAttribute('domain');

        if (! is_string($host) || $host === '') {
            return '';
        }

        $suffix = '.'.config()->string('app.central_domain');

        if (str_ends_with($host, $suffix)) {
            return substr($host, 0, -strlen($suffix));
        }

        return $host;
    }
}
