<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Tests\Fixtures;

use Nvl\Tenancy\Contracts\TenantDirectory;
use Nvl\Tenancy\Exceptions\TenantNotFound;
use Nvl\Tenancy\ValueObjects\TenantDescriptor;
use Nvl\Tenancy\ValueObjects\TenantId;

/** Provides explicit directory entries for isolated tenancy tests. */
final class ArrayTenantDirectory implements TenantDirectory
{
    /** @param array<string, TenantDescriptor> $tenants */
    public function __construct(public array $tenants) {}

    /** Find one explicitly configured test tenant. */
    public function find(TenantId $tenant): TenantDescriptor
    {
        return $this->tenants[$tenant->value] ?? throw new TenantNotFound;
    }
}
