<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Enums;

/**
 * Identifies the isolation scope currently installed for tenant-aware work.
 */
enum TenantContextMode: string
{
    case Disabled = 'disabled';
    case Unresolved = 'unresolved';
    case Tenant = 'tenant';
    case Platform = 'platform';
}
