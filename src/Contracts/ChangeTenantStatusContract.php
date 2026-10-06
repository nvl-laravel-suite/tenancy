<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Contracts;

use Nvl\Support\Tenancy\Enums\TenantStatus;
use Nvl\Support\Tenancy\Exceptions\TenantConfigurationInvalid;
use Nvl\Support\Tenancy\Exceptions\TenantNotFound;
use Nvl\Support\Tenancy\ValueObjects\PlatformOperation;
use Nvl\Support\Tenancy\ValueObjects\TenantId;

/**
 * Defines the consumer-facing ChangeTenantStatusAction workflow.
 *
 * @api
 */
interface ChangeTenantStatusContract
{
    /**
     * Persist one audited tenant lifecycle transition.
     *
     * @throws TenantConfigurationInvalid When the effective directory is not package-owned
     * @throws TenantNotFound When the tenant identifier is unknown
     */
    public function execute(TenantId $tenant, TenantStatus $status, PlatformOperation $operation): void;
}
