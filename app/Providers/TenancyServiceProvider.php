<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Tenancy\Jobs\MarkTenantActive;
use App\Domain\Tenancy\Jobs\MarkTenantFailed;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Stancl\JobPipeline\JobPipeline;
use Stancl\Tenancy\Events;
use Stancl\Tenancy\Jobs;
use Stancl\Tenancy\Listeners;
use Stancl\Tenancy\Middleware;
use Throwable;

/**
 * Wires stancl/tenancy lifecycle events to their listeners + job pipelines.
 *
 * This is the load-bearing glue for DB-per-tenant isolation:
 *  - TenancyInitialized → BootstrapTenancy runs the bootstrappers
 *    (DatabaseTenancyBootstrapper switches the default connection to the tenant
 *    DB; without this, ALL queries hit the central DB and isolation is broken).
 *  - TenantCreated → CreateDatabase + MigrateDatabase provisions and migrates
 *    one physical PostgreSQL database per tenant (multitenancy §1, §3).
 *  - TenantDeleted → DeleteDatabase drops the tenant DB.
 *
 * Route mapping is intentionally NOT done here — tenant routes are registered
 * in bootstrap/app.php's `then` callback, so duplicating it would double-bind
 * routes. The Action layer dispatches stancl's Events\TenantCreated (via the
 * model's lifecycle), which this provider listens to.
 *
 * Provisioning is QUEUED (`shouldBeQueued(true)`). This is required for
 * correctness, not just performance: the CreateTenant Action commits the
 * central registry row inside a `DB::transaction()`, and PostgreSQL forbids
 * `CREATE DATABASE` inside a transaction block. Queuing defers provisioning out
 * of that transaction (and off the request's slow path — multitenancy §3). A
 * queue worker (or `queue:work`) must be running to drain the provisioning
 * pipeline; until the tenant DB is created the tenant's status stays Pending.
 *
 * The cross-tenant isolation test drives provisioning explicitly (CreateDatabase
 * + tenants:migrate, outside any transaction) so it does not depend on a worker.
 *
 * FAILURE PATH (B1 — fail loud, AGENTS Ley 6): the provisioning JobPipeline has
 * NO catch in this stancl/jobpipeline version. If CreateDatabase / MigrateDatabase
 * / SeedDatabase throws, the queued JobPipeline job simply fails and — without a
 * handler — the tenant is left stuck Pending forever with a half-built DB, making
 * the Failed status + EnsureTenantIsActive 503 + operator visibility unreachable.
 * We close that hole with a `Queue::failing` listener: when a JobPipeline job
 * carrying a Tenant fails, we dispatch MarkTenantFailed, which flips the central
 * status Pending → Failed (guarded + idempotent).
 */
final class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->bootEvents();
        $this->bootProvisioningFailureHandler();
        $this->makeTenancyMiddlewareHighestPriority();
    }

    /**
     * Mark a tenant Failed when its provisioning pipeline job fails on the queue.
     *
     * The provisioning stages (CreateDatabase / MigrateDatabase / SeedDatabase)
     * have no `failed()` hook, so a throwable propagates out of JobPipeline::handle
     * and fails the queued JobPipeline job. We listen for that failure, recover the
     * Tenant carried by the pipeline (the `send` callback's return value, stored as
     * `passable`) and dispatch MarkTenantFailed — which guards the Pending → Failed
     * transition and is idempotent. We only react to a JobPipeline whose passable is
     * a Tenant, so non-provisioning failures (e.g. TenantDeleted's DeleteDatabase)
     * are ignored.
     */
    private function bootProvisioningFailureHandler(): void
    {
        Queue::failing(static function (JobFailed $event): void {
            $pipeline = self::resolveJobPipeline($event);

            if ($pipeline === null) {
                return;
            }

            $tenant = is_array($pipeline->passable) ? ($pipeline->passable[0] ?? null) : null;

            if (! $tenant instanceof Tenant) {
                return;
            }

            MarkTenantFailed::dispatch($tenant, $event->exception->getMessage());
        });
    }

    /**
     * Recover the JobPipeline command object from a failed queue job's payload.
     * Returns null if the failed job is not a serialized JobPipeline.
     */
    private static function resolveJobPipeline(JobFailed $event): ?JobPipeline
    {
        try {
            $payload = $event->job->payload();
        } catch (Throwable) {
            return null;
        }

        $serialized = $payload['data']['command'] ?? null;

        if (! is_string($serialized) || ! str_starts_with($serialized, 'O:')) {
            return null;
        }

        try {
            $command = unserialize($serialized);
        } catch (Throwable) {
            return null;
        }

        return $command instanceof JobPipeline ? $command : null;
    }

    /**
     * Tenancy lifecycle event → listener map.
     *
     * @return array<class-string, array<int, JobPipeline|callable|class-string>>
     */
    private function events(): array
    {
        return [
            Events\TenantCreated::class => [
                JobPipeline::make([
                    Jobs\CreateDatabase::class,
                    Jobs\MigrateDatabase::class,
                    Jobs\SeedDatabase::class,
                    MarkTenantActive::class,
                ])->send(static fn (Events\TenantCreated $event) => $event->tenant)
                    ->shouldBeQueued(true),
            ],

            Events\TenantDeleted::class => [
                JobPipeline::make([
                    Jobs\DeleteDatabase::class,
                ])->send(static fn (Events\TenantDeleted $event) => $event->tenant)
                    ->shouldBeQueued(true),
            ],

            Events\TenancyInitialized::class => [
                Listeners\BootstrapTenancy::class,
            ],

            Events\TenancyEnded::class => [
                Listeners\RevertToCentralContext::class,
            ],

            Events\SyncedResourceSaved::class => [
                Listeners\UpdateSyncedResource::class,
            ],
        ];
    }

    private function bootEvents(): void
    {
        foreach ($this->events() as $event => $listeners) {
            foreach ($listeners as $listener) {
                if ($listener instanceof JobPipeline) {
                    $listener = $listener->toListener();
                }

                Event::listen($event, $listener);
            }
        }
    }

    private function makeTenancyMiddlewareHighestPriority(): void
    {
        $tenancyMiddleware = [
            Middleware\PreventAccessFromCentralDomains::class,
            Middleware\InitializeTenancyByDomain::class,
            Middleware\InitializeTenancyBySubdomain::class,
            Middleware\InitializeTenancyByDomainOrSubdomain::class,
            Middleware\InitializeTenancyByPath::class,
            Middleware\InitializeTenancyByRequestData::class,
        ];

        /** @var Kernel $kernel */
        $kernel = $this->app->make(Kernel::class);

        foreach (array_reverse($tenancyMiddleware) as $middleware) {
            $kernel->prependToMiddlewarePriority($middleware);
        }
    }
}
