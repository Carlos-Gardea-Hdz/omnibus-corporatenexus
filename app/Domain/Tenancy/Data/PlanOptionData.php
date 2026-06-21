<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Data;

use App\Domain\Tenancy\Enums\TenantPlan;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Output contract for one selectable plan in the signup plan picker.
 *
 * Property names are snake_case so the generated Inertia prop is snake_case
 * (inertia-react §props). Price is INTEGER CENTS (never float — project law);
 * the React layer formats cents → currency. `seat_limit === 0` means unlimited.
 */
#[TypeScript]
final class PlanOptionData extends Data
{
    public function __construct(
        public string $value,        // TenantPlan->value
        public string $label,        // ->label() (bilingual)
        public int $price_cents,     // integer cents
        public int $seat_limit,      // 0 = unlimited
    ) {}

    public static function fromEnum(TenantPlan $plan): self
    {
        return new self(
            $plan->value,
            $plan->label(),
            $plan->priceCents(),
            $plan->seatLimit(),
        );
    }
}
