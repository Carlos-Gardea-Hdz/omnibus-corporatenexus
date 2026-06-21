/**
 * Shared Inertia props — typed via the v2 `InertiaConfig` augmentation so
 * `usePage()` is typed across the whole app. Server shapes come from Spatie
 * Data DTOs through the TypeScript transformer (see generated.d.ts, which
 * declares the global `App.Domain.*` namespaces); never hand-redeclare them.
 */
declare module '@inertiajs/core' {
    interface InertiaConfig {
        sharedPageProps: {
            tenant: App.Domain.Tenancy.Data.TenantData | null;
            locale: string;
            flash: {
                success: string | null;
                error: string | null;
                temp_password: string | null;
            };
            cspNonce: string | null;
        };
    }
}

export {};
