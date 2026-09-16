<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Contracts;

use Nvl\Tenancy\Exceptions\TenantNotFound;
use Nvl\Tenancy\ValueObjects\TenantDescriptor;
use Nvl\Tenancy\ValueObjects\TenantId;

/**
 * Resolves immutable tenant lifecycle descriptors by canonical identifier.
 */
interface TenantDirectory
{
    /**
     * Find one tenant directory entry.
     *
     * @throws TenantNotFound When the identifier is unknown
     */
    public function find(TenantId $tenant): TenantDescriptor;
}
