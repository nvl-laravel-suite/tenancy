<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Services;

use Nvl\Tenancy\Contracts\PlatformAccess;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\ValueObjects\PlatformOperation;

/** Denies privileged operations until the host supplies explicit authorization. */
final class DenyPlatformAccess implements PlatformAccess
{
    /** Deny every operation regardless of actor type. */
    public function authorize(PlatformOperation $operation): void
    {
        throw new TenantBoundaryViolation;
    }
}
