<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Services;

use Nvl\Support\Tenancy\Services\TenantExtensionGuard;
use Nvl\Tenancy\Support\LegacyNeutralAlias;

/** @deprecated Use \Nvl\Support\Tenancy\Services\TenantExtensionGuard; retained for one major release. */
LegacyNeutralAlias::register(TenantExtensionGuard::class, 'Nvl\\Tenancy\\Services\\TenantExtensionGuard');
