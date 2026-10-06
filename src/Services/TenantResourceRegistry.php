<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Services;

use Nvl\Support\Tenancy\Services\TenantResourceRegistry;
use Nvl\Tenancy\Support\LegacyNeutralAlias;

/**
 * @deprecated Use \Nvl\Support\Tenancy\Services\TenantResourceRegistry; retained for one major release.
 *
 * @api
 */
LegacyNeutralAlias::register(TenantResourceRegistry::class, 'Nvl\\Tenancy\\Services\\TenantResourceRegistry');
