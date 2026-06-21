<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Domain\Platform\Actions\ChangeTenantPlan;
use App\Domain\Platform\Actions\ReactivateTenant;
use App\Domain\Platform\Actions\SuspendTenant;
use App\Domain\Platform\Data\ChangeTenantPlanData;
use App\Domain\Platform\Data\PlatformAdminData;
use App\Domain\Platform\Data\TenantDetailData;
use App\Domain\Platform\Models\PlatformAdmin;
use App\Domain\Tenancy\Enums\TenantPlan;
use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Models\Tenant;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Platform-admin tenant detail + lifecycle controls (slice 003, §3 CONTRACT).
 *
 * CENTRAL-ONLY. Route-model binding resolves the {@see Tenant} on the central
 * connection (UUIDv7). The lifecycle Actions own the transition guard and the
 * central transaction; illegal transitions surface as a graceful 302 + error
 * via the TenantTransitionException render in bootstrap/app.php (never 500).
 * This controller never enters tenant context or touches a per-tenant model.
 */
final class PlatformTenantController extends Controller
{
    public function show(Tenant $tenant): Response
    {
        /** @var PlatformAdmin $admin */
        $admin = Auth::guard('admin')->user();

        return Inertia::render('Platform/Tenants/Show', [
            'admin' => PlatformAdminData::fromModel($admin),
            'tenant' => TenantDetailData::fromModel($tenant),
            'allowed_transitions' => $this->allowedTransitions($tenant),
            'assignable_plans' => $this->assignablePlans(),
            'can' => [
                'suspend' => $tenant->status->canTransitionTo(TenantStatus::Suspended),
                'reactivate' => $tenant->status === TenantStatus::Suspended
                    && $tenant->status->canTransitionTo(TenantStatus::Active),
                'change_plan' => true,
            ],
        ]);
    }

    public function suspend(Tenant $tenant, SuspendTenant $action): RedirectResponse
    {
        $action->handle($tenant);

        return redirect()
            ->route('platform.tenants.show', $tenant)
            ->with('success', __('platform.actions.suspend_success'));
    }

    public function reactivate(Tenant $tenant, ReactivateTenant $action): RedirectResponse
    {
        $action->handle($tenant);

        return redirect()
            ->route('platform.tenants.show', $tenant)
            ->with('success', __('platform.actions.reactivate_success'));
    }

    public function changePlan(Tenant $tenant, ChangeTenantPlanData $data, ChangeTenantPlan $action): RedirectResponse
    {
        $action->handle($tenant, $data->plan);

        return redirect()
            ->route('platform.tenants.show', $tenant)
            ->with('success', __('platform.actions.plan_success'));
    }

    /**
     * The set of statuses the tenant may legally transition to, as option rows.
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function allowedTransitions(Tenant $tenant): array
    {
        return array_values(array_map(
            static fn (TenantStatus $status): array => [
                'value' => $status->value,
                'label' => $status->label(),
            ],
            array_filter(
                TenantStatus::cases(),
                static fn (TenantStatus $status): bool => $tenant->status->canTransitionTo($status),
            ),
        ));
    }

    /**
     * Every plan a platform admin may assign, as option rows.
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function assignablePlans(): array
    {
        return array_map(
            static fn (TenantPlan $plan): array => [
                'value' => $plan->value,
                'label' => $plan->label(),
            ],
            TenantPlan::cases(),
        );
    }
}
