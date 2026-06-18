<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Data;

use App\Domain\Tenancy\Enums\TenantPlan;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Attributes\Validation\AlphaDash;
use Spatie\LaravelData\Attributes\Validation\Email;
use Spatie\LaravelData\Attributes\Validation\Lowercase;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Input contract for provisioning a tenant. Server-side validation is the
 * source of truth (security §0); the matching TS type is generated for the
 * Inertia/React form so the client never re-declares the shape.
 */
#[TypeScript]
final class CreateTenantData extends Data
{
    public function __construct(
        #[Min(2), Max(120)]
        public string $name,
        /** Subdomain label, e.g. "acme" → acme.nexus.carlosgardea.com */
        #[Min(2), Max(63), AlphaDash, Lowercase]
        public string $subdomain,
        #[Email, Max(255)]
        public string $ownerEmail,
        public TenantPlan $plan = TenantPlan::Free,
    ) {}

    /**
     * Extra rules that cannot be expressed as attributes (reserved subdomains).
     *
     * @return array<string, list<ValidationRule>>
     */
    public static function rules(): array
    {
        /** @var ValidationRule $notReserved */
        $notReserved = Rule::notIn(['www', 'app', 'admin', 'api', 'mail', 'central', 'nexus']);

        return [
            'subdomain' => [$notReserved],
        ];
    }
}
