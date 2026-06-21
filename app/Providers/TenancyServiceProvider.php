<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Stancl\JobPipeline\JobPipeline;
use Stancl\Tenancy\Events;
use Stancl\Tenancy\Jobs;
use Stancl\Tenancy\Listeners;
use Stancl\Tenancy\Middleware;

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
        $this->makeTenancyMiddlewareHighestPriority();
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
