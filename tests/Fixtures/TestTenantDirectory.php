<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Tests\Fixtures;

use LogicException;
use Nvl\Support\Tenancy\Contracts\TenantDirectory;
use Nvl\Support\Tenancy\Exceptions\TenantNotFound;
use Nvl\Support\Tenancy\ValueObjects\TenantDescriptor;
use Nvl\Support\Tenancy\ValueObjects\TenantId;

/**
 * Test-only host directory used to verify configured adapter contracts.
 */
final class TestTenantDirectory implements TenantDirectory
{
    /**
     * Fail if structural configuration validation constructs the adapter.
     */
    public function __construct()
    {
        throw new LogicException('Configuration validation constructed an adapter.');
    }

    /**
     * Reject every lookup because configuration tests never resolve tenants.
     *
     * @throws TenantNotFound Always
     */
    public function find(TenantId $tenant): TenantDescriptor
    {
        throw new TenantNotFound('Tenant was not found.');
    }
}
