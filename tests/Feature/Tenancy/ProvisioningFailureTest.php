<?php

declare(strict_types=1);

use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Jobs\MarkTenantFailed;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Container\Container;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Stancl\JobPipeline\JobPipeline;

/*
| B1 — a FAILED provisioning pipeline must mark the tenant Failed.
|
| The provisioning JobPipeline (CreateDatabase → MigrateDatabase → SeedDatabase →
| MarkTenantActive) has no catch in this stancl/jobpipeline version: if a stage
| throws, the queued JobPipeline job fails and — without a handler — the tenant
| is stuck Pending forever with a half-built DB (Failed status + the 503 +
| operator visibility all unreachable). The Queue::failing listener wired in
| TenancyServiceProvider closes that hole by dispatching MarkTenantFailed.
|
| Central-only registry writes, so RefreshDatabase is safe here.
*/

uses(RefreshDatabase::class);

/**
 * Throwing provisioning stage — stands in for a CreateDatabase/MigrateDatabase/
 * SeedDatabase that blows up. It has no `failed()` hook, exactly like the real
 * stancl jobs, so JobPipeline::handle re-throws and the queued job fails.
 */
final class ThrowingProvisioningStage
{
    public function __construct(public readonly Tenant $tenant) {}

    public function handle(): void
    {
        throw new RuntimeException('seeder pointed at a missing class');
    }
}

/** Build the production-shaped provisioning pipeline with a throwing stage. */
function failingProvisioningPipeline(Tenant $tenant): JobPipeline
{
    return JobPipeline::make([ThrowingProvisioningStage::class])
        ->send(static fn (Tenant $t) => $t)
        ->shouldBeQueued(true)
        ->executable([$tenant]);
}

/** Emit the JobFailed event a queue worker would fire for the given command. */
function fireJobFailed(object $command, Throwable $exception): void
{
    $payload = json_encode(['data' => ['command' => serialize($command)]], JSON_THROW_ON_ERROR);

    $job = new SyncJob(Container::getInstance(), $payload, 'sync', 'default');

    event(new JobFailed('sync', $job, $exception));
}

it('dispatches MarkTenantFailed when a provisioning pipeline job fails (B1)', function (): void {
    Bus::fake([MarkTenantFailed::class]);

    /** @var Tenant $tenant */
    $tenant = Tenant::factory()->pending()->create(['name' => 'Doomed Co']);

    fireJobFailed(failingProvisioningPipeline($tenant), new RuntimeException('boom'));

    Bus::assertDispatched(
        MarkTenantFailed::class,
        fn (MarkTenantFailed $job): bool => $job->tenant->is($tenant),
    );
});

it('flips the central status Pending → Failed end-to-end on pipeline failure (B1)', function (): void {
    // Real dispatch (not faked) so MarkTenantFailed actually runs against central.
    Queue::fake()->except(MarkTenantFailed::class);

    /** @var Tenant $tenant */
    $tenant = Tenant::factory()->pending()->create(['name' => 'Stuck Co']);
    expect($tenant->status)->toBe(TenantStatus::Pending);

    fireJobFailed(failingProvisioningPipeline($tenant), new RuntimeException('migration failed'));

    // Central registry status is now Failed (not stuck Pending) — re-read fresh.
    $fresh = Tenant::query()->findOrFail($tenant->getKey());
    expect($fresh->status)->toBe(TenantStatus::Failed);
});

it('ignores a non-provisioning job failure (a plain failed job carries no Tenant)', function (): void {
    Bus::fake([MarkTenantFailed::class]);

    // A failed job whose command is NOT a JobPipeline → listener must no-op.
    $unrelated = new ThrowingProvisioningStage(Tenant::factory()->pending()->create());

    fireJobFailed($unrelated, new RuntimeException('unrelated'));

    Bus::assertNotDispatched(MarkTenantFailed::class);
});

it('marks Failed idempotently and only from a retryable status (guarded transition)', function (): void {
    Queue::fake()->except(MarkTenantFailed::class);

    // An ARCHIVED tenant cannot transition to Failed (default-deny) — the handler
    // must leave it untouched, never 500.
    /** @var Tenant $tenant */
    $tenant = Tenant::factory()->create(['status' => TenantStatus::Archived]);

    fireJobFailed(failingProvisioningPipeline($tenant), new RuntimeException('late failure'));

    $fresh = Tenant::query()->findOrFail($tenant->getKey());
    expect($fresh->status)->toBe(TenantStatus::Archived);
});
