<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Enums;

use Nvl\Support\Contracts\ResponseCode;

/**
 * Defines stable machine-readable codes for tenancy failures.
 */
enum TenancyResponseCode: string implements ResponseCode
{
    case TenantContextMissing = 'tenant_context_missing';
    case TenantNotFound = 'tenant_not_found';
    case TenantInactive = 'tenant_inactive';
    case TenantBoundaryViolation = 'tenant_boundary_violation';
    case TenantConfigurationInvalid = 'tenant_configuration_invalid';
    case TenantSchemaNotReady = 'tenant_schema_not_ready';
}
