<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Services;

use Nvl\Support\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Support\Tenancy\ValueObjects\PlatformOperation;
use Nvl\Tenancy\Contracts\PlatformAccess;

/** Denies privileged operations until the host supplies explicit authorization. */
final class DenyPlatformAccess implements PlatformAccess
{
    /** Deny every operation regardless of actor type. */
    public function authorize(PlatformOperation $operation): void
    {
        throw new TenantBoundaryViolation;
    }
}
