<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Services;

use Closure;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\ValueObjects\TenantId;

/**
 * Holds the synchronous, tenant-specific recovery capability for one application scope.
 *
 * @internal Only TenantMaintenanceRunner may grant this lease.
 */
final class TenantMaintenanceLease
{
    private ?TenantId $tenant = null;

    /** Determine whether synchronous recovery currently owns the scope. */
    public function active(): bool
    {
        return $this->tenant !== null;
    }

    /** Determine whether the requested tenant is the sole recovery tenant. */
    public function admits(TenantId $tenant): bool
    {
        return $this->tenant?->value === $tenant->value;
    }

    /**
     * Grant one lease and revoke it even when recovery fails.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function during(TenantId $tenant, Closure $callback): mixed
    {
        if ($this->active()) {
            throw new TenantBoundaryViolation('A maintenance lease is already active.');
        }
        $this->tenant = $tenant;
        try {
            return $callback();
        } finally {
            $this->tenant = null;
        }
    }

    /** Deny queue publication while synchronous recovery is active. */
    public function assertQueueAllowed(): void
    {
        if ($this->active()) {
            throw new TenantBoundaryViolation('Queue dispatch is forbidden during tenant maintenance.');
        }
    }
}
