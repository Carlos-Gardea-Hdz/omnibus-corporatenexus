<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Exceptions;

use RuntimeException;

final class TenantProvisioningException extends RuntimeException
{
    public static function subdomainTaken(string $subdomain): self
    {
        return new self("The subdomain [{$subdomain}] is already in use.");
    }
}
