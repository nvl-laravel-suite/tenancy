<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Services;

use Illuminate\Contracts\Config\Repository;
use Nvl\Tenancy\Contracts\TenantContext;
use Nvl\Tenancy\Enums\TenantContextMode;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Exceptions\TenantContextMissing;
use Nvl\Tenancy\ValueObjects\TenantContextSnapshot;
use Nvl\Tenancy\ValueObjects\TenantId;

/**
 * Stores mutable tenant state inside one Laravel application scope.
 *
 * @internal Consumers receive the read-only TenantContext contract.
 */
final class ScopedTenantContext implements TenantContext
{
    private TenantContextSnapshot $current;

    private bool $invalid = false;

    /**
     * Initialize the context from deployment-level feature configuration.
     */
    public function __construct(Repository $configuration)
    {
        $this->current = new TenantContextSnapshot(
            $configuration->get('tenancy.enabled') === true
                ? TenantContextMode::Unresolved
                : TenantContextMode::Disabled,
        );
    }

    /**
     * Return the current immutable context snapshot.
     */
    public function snapshot(): TenantContextSnapshot
    {
        return $this->current;
    }

    /**
     * Return the active tenant identifier.
     *
     * @throws TenantContextMissing When no tenant is active
     */
    public function requireTenant(): TenantId
    {
        return $this->current->tenantId
            ?? throw new TenantContextMissing('Tenant context is not resolved.');
    }

    /**
     * Replace state only for the package execution boundary.
     *
     * @internal Consumer code must use TenantRunner instead.
     */
    public function replace(TenantContextSnapshot $next): void
    {
        if ($this->invalid) {
            throw new TenantBoundaryViolation('The tenant scope was invalidated by failed cleanup.');
        }
        $this->current = $next;
    }

    /**
     * Revoke the scope after restoration or transaction corruption.
     *
     * @internal
     */
    public function invalidate(): void
    {
        $this->invalid = true;
        $this->current = new TenantContextSnapshot(TenantContextMode::Unresolved);
    }

    /**
     * Check whether cleanup has revoked this application scope.
     *
     * @internal
     */
    public function invalid(): bool
    {
        return $this->invalid;
    }
}
