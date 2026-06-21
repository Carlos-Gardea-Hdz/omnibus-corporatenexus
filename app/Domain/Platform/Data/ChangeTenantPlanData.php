<?php

declare(strict_types=1);

namespace App\Domain\Platform\Data;

use App\Domain\Tenancy\Enums\TenantPlan;
use Spatie\LaravelData\Attributes\Validation\Enum;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Input contract for changing a tenant's billing plan from the console
 * (slice 003). Server-side validation is the source of truth; the matching TS
 * type is generated for the React form. Web validation surfaces as 302 +
 * session errors, never 422.
 */
#[TypeScript]
final class ChangeTenantPlanData extends Data
{
    public function __construct(
        #[Required, Enum(TenantPlan::class)]
        public TenantPlan $plan,
    ) {}
}
