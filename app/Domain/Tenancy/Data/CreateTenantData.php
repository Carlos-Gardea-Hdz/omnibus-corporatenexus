<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Data;

use App\Domain\Tenancy\Enums\TenantPlan;
use App\Domain\Tenancy\Rules\UniqueSubdomain;
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
     * Extra rules that cannot be expressed as attributes: the reserved-subdomain
     * allowlist and uniqueness of the derived host. Uniqueness is enforced here
     * (302 + session error) AND defensively re-checked in the CreateTenant
     * Action (race-safe exception) — never trust a single layer (security §0).
     *
     * @return array<string, list<ValidationRule|string>>
     */
    public static function rules(): array
    {
        /** @var ValidationRule $notReserved */
        $notReserved = Rule::notIn([
            'www', 'app', 'admin', 'api', 'mail', 'central', 'nexus',
            'dashboard', 'billing', 'support', 'status', 'assets',
            'static', 'cdn', 'blog', 'help', 'docs', 'internal',
        ]);

        // Spatie Data's rules() OVERRIDES the attribute rules for this field, so the
        // full subdomain contract must live here — NOT just the reserved/unique extras.
        // The regex is a real DNS label (lowercase alphanumeric segments joined by single
        // dashes): it rejects underscores and leading/trailing/consecutive dashes that
        // AlphaDash would wrongly allow.
        return [
            'subdomain' => [
                'required', 'string', 'min:2', 'max:63',
                'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/',
                $notReserved,
                new UniqueSubdomain,
            ],
        ];
    }
}
