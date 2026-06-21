<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Tenancy\Data\TenantData;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Http\Request;
use Inertia\Middleware;

final class HandleInertiaRequests extends Middleware
{
    /**
     * The root template loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Props shared with every Inertia response.
     *
     * Only safe, DTO-shaped data is exposed — every prop is serialized into
     * the page payload and visible to the client (inertia-react §6). The
     * tenant context (name/plan) powers the shell header; locale drives the
     * bilingual ES/EN copy; the CSP nonce is forwarded for inline bootstrap.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $tenant = function_exists('tenant') ? tenant() : null;

        return [
            ...parent::share($request),
            'tenant' => $tenant instanceof Tenant
                ? TenantData::fromModel($tenant)
                : null,
            'locale' => app()->getLocale(),
            'flash' => [
                'success' => fn (): ?string => $this->stringFlash($request, 'success'),
                'error' => fn (): ?string => $this->stringFlash($request, 'error'),
                // One-shot reveal of a freshly invited member's temp password
                // (slice 002, F3). A transient flash only — never a list prop,
                // never logged. The inviter sees it once, then it is gone.
                'temp_password' => fn (): ?string => $this->stringFlash($request, 'temp_password'),
            ],
            'cspNonce' => $request->attributes->get('csp_nonce'),
        ];
    }

    private function stringFlash(Request $request, string $key): ?string
    {
        $value = $request->session()->get($key);

        return is_string($value) ? $value : null;
    }
}
