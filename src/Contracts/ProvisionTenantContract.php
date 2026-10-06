<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Contracts;

use Nvl\Support\Tenancy\Exceptions\TenantConfigurationInvalid;
use Nvl\Support\Tenancy\ValueObjects\PlatformOperation;
use Nvl\Support\Tenancy\ValueObjects\TenantDescriptor;

/**
 * Defines the consumer-facing ProvisionTenantAction workflow.
 *
 * @api
 */
interface ProvisionTenantContract
{
    /**
     * Provision an active tenant with a server-generated identifier.
     *
     * @throws TenantConfigurationInvalid When the name or effective directory is incompatible
     */
    public function execute(string $name, PlatformOperation $operation): TenantDescriptor;
}
