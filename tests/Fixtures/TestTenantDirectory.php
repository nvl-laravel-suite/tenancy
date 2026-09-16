<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Tests\Fixtures;

use Nvl\Tenancy\Contracts\TenantDirectory;
use Nvl\Tenancy\Exceptions\TenantNotFound;
use Nvl\Tenancy\ValueObjects\TenantDescriptor;
use Nvl\Tenancy\ValueObjects\TenantId;

/**
 * Test-only host directory used to verify configured adapter contracts.
 */
final class TestTenantDirectory implements TenantDirectory
{
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
