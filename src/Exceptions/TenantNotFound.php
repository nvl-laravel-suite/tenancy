<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Exceptions;

use Nvl\Support\Tenancy\Exceptions\TenantNotFound;
use Nvl\Tenancy\Support\LegacyNeutralAlias;

/** @deprecated Use \Nvl\Support\Tenancy\Exceptions\TenantNotFound; retained for one major release. */
LegacyNeutralAlias::register(TenantNotFound::class, 'Nvl\\Tenancy\\Exceptions\\TenantNotFound');
