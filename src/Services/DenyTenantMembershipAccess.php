<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Tenancy\Contracts\TenantMembershipAccess;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\ValueObjects\TenantId;

/** Denies membership until the host supplies explicit authorization. */
final class DenyTenantMembershipAccess implements TenantMembershipAccess
{
    /** Deny admission for every actor and tenant. */
    public function assertMember(Authenticatable $actor, TenantId $tenant): void
    {
        throw new TenantBoundaryViolation;
    }
}
