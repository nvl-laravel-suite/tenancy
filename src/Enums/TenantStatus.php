<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Enums;

/**
 * Describes the lifecycle state of a tenant directory entry.
 */
enum TenantStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Deleted = 'deleted';
}
