<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Domain\Platform\Data\PlatformAdminData;
use App\Domain\Platform\Data\TenantSummaryData;
use App\Domain\Platform\Models\PlatformAdmin;
use App\Domain\Tenancy\Enums\TenantPlan;
use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Models\Tenant;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Platform-admin dashboard: the tenant registry list (slice 003, §3 CONTRACT).
 *
 * CENTRAL-ONLY. Reads the central `tenants` registry (with its domains) — it
 * never initializes tenancy and never queries a per-tenant model. The list is
 * filterable by status/plan; the `counts.by_status` aggregate is ALWAYS
 * unfiltered (the at-a-glance fleet health), computed via a grouped count on
 * the central connection.
 */
final class PlatformDashboardController extends Controller
{
    public function index(): Response
    {
        $status = TenantStatus::tryFrom($this->queryString('status'));
        $plan = TenantPlan::tryFrom($this->queryString('plan'));

        $tenants = Tenant::query()
            ->with('domains')
            ->when($status, static fn ($query, TenantStatus $value) => $query->where('status', $value->value))
            ->when($plan, static fn ($query, TenantPlan $value) => $query->where('plan', $value->value))
            ->orderByDesc('created_at')
            ->paginate(15)
            ->through(static fn (Tenant $tenant): TenantSummaryData => TenantSummaryData::fromModel($tenant));

        /** @var PlatformAdmin $admin */
        $admin = Auth::guard('admin')->user();

        return Inertia::render('Platform/Dashboard', [
            'admin' => PlatformAdminData::fromModel($admin),
            'tenants' => $tenants,
            'filters' => [
                'status' => $status?->value,
                'plan' => $plan?->value,
            ],
            'status_options' => $this->enumOptions(TenantStatus::cases()),
            'plan_options' => $this->enumOptions(TenantPlan::cases()),
            'counts' => [
                'total' => Tenant::query()->count(),
                'by_status' => Tenant::query()
                    ->selectRaw('status, count(*) as aggregate')
                    ->groupBy('status')
                    ->pluck('aggregate', 'status'),
            ],
        ]);
    }

    /**
     * Read a single string query-string value, defaulting to '' when absent or
     * non-scalar. Feeding '' to a backed enum's tryFrom() yields null (no match).
     */
    private function queryString(string $key): string
    {
        $value = request()->query($key);

        return is_string($value) ? $value : '';
    }

    /**
     * Build {value,label} option rows for a set of backed enum cases.
     *
     * @param  array<int, TenantStatus|TenantPlan>  $cases
     * @return array<int, array{value: string, label: string}>
     */
    private function enumOptions(array $cases): array
    {
        return array_map(
            static fn (TenantStatus|TenantPlan $case): array => [
                'value' => $case->value,
                'label' => $case->label(),
            ],
            $cases,
        );
    }
}
