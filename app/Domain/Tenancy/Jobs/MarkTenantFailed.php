<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Jobs;

use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fail-loud terminal for a broken provisioning pipeline (AGENTS Ley 6 — never
 * swallow). If CreateDatabase / MigrateDatabase / SeedDatabase throws, the
 * tenant is left stuck in Pending; this job flips the CENTRAL status
 * Pending → Failed and logs the cause so the failure is observable rather than
 * silently incomplete.
 *
 * Reloads from the central connection by id (the pipeline may have ended tenant
 * context before failing). Idempotent and default-deny: only transitions when
 * the status allowlist permits it.
 */
final class MarkTenantFailed implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Tenant $tenant,
        public readonly ?string $reason = null,
    ) {}

    public function handle(): void
    {
        $centralConnection = config()->string('tenancy.database.central_connection');

        /** @var Tenant|null $tenant */
        $tenant = Tenant::on($centralConnection)->find($this->tenant->getKey());

        if ($tenant === null) {
            return;
        }

        Log::error('Tenant provisioning failed.', [
            'tenant_id' => $tenant->getKey(),
            'reason' => $this->reason,
        ]);

        if ($tenant->status === TenantStatus::Failed) {
            return; // Idempotent.
        }

        if (! $tenant->status->canTransitionTo(TenantStatus::Failed)) {
            return; // Default-deny.
        }

        $tenant->status = TenantStatus::Failed;
        $tenant->save();
    }

    /**
     * Surface the original throwable if THIS job itself fails on the queue.
     */
    public function failed(Throwable $exception): void
    {
        Log::critical('MarkTenantFailed job itself failed.', [
            'tenant_id' => $this->tenant->getKey(),
            'exception' => $exception->getMessage(),
        ]);
    }
}
