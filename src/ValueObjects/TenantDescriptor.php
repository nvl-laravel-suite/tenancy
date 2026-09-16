<?php

declare(strict_types=1);

namespace Nvl\Tenancy\ValueObjects;

use Nvl\Tenancy\Enums\TenantStatus;

/**
 * Describes one immutable tenant directory entry.
 */
final readonly class TenantDescriptor
{
    /**
     * Create a tenant directory descriptor.
     */
    public function __construct(
        public TenantId $id,
        public TenantStatus $status,
    ) {}
}
