<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Tests\Fixtures;

use LogicException;
use Nvl\Tenancy\Contracts\TenantDirectory;
use Nvl\Tenancy\ValueObjects\TenantDescriptor;
use Nvl\Tenancy\ValueObjects\TenantId;

/**
 * Conflicting binding fixture that must never be constructed during validation.
 */
final class ConflictingTestTenantDirectory implements TenantDirectory
{
    /**
     * Fail if configuration validation constructs the conflicting adapter.
     */
    public function __construct()
    {
        throw new LogicException('Configuration validation constructed an adapter.');
    }

    /**
     * Return type implementation required by the directory contract.
     */
    public function find(TenantId $tenant): TenantDescriptor
    {
        throw new LogicException('Configuration validation invoked an adapter.');
    }
}
