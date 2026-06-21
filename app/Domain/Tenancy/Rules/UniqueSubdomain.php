<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Rejects a subdomain whose derived host ({subdomain}.{central_domain}) is
 * already registered in the central `domains` table.
 *
 * Lives in the domain (not a stancl/Http concern) and queries ONLY the central
 * connection — the registry is central data, never resolved from a tenant DB
 * (multitenancy §1). Validation failure surfaces as a 302 + session error via
 * the Spatie Data controller binding; the CreateTenant Action re-checks the
 * same invariant inside its transaction for race safety (defence in depth).
 */
final readonly class UniqueSubdomain implements ValidationRule
{
    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return; // Format/length attributes handle non-strings.
        }

        $centralDomain = config()->string('app.central_domain');
        $centralConnection = config()->string('tenancy.database.central_connection');
        $host = mb_strtolower($value).'.'.$centralDomain;

        $taken = DB::connection($centralConnection)
            ->table('domains')
            ->where('domain', $host)
            ->exists();

        if ($taken) {
            $fail('tenancy.subdomain.taken')->translate();
        }
    }
}
