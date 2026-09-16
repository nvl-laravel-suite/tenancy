<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Contracts;

use Nvl\Tenancy\ValueObjects\TenantContextSnapshot;
use Nvl\Tenancy\ValueObjects\TenantId;

/**
 * Exposes the current tenant scope without permitting callers to mutate it.
 */
interface TenantContext
{
    /**
     * Return the current immutable context snapshot.
     */
    public function snapshot(): TenantContextSnapshot;

    /**
     * Return the active tenant identifier.
     */
    public function requireTenant(): TenantId;
}
