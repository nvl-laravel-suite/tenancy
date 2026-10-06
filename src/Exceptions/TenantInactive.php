<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Exceptions;

use Nvl\Support\Tenancy\Exceptions\TenantInactive;
use Nvl\Tenancy\Support\LegacyNeutralAlias;

/** @deprecated Use \Nvl\Support\Tenancy\Exceptions\TenantInactive; retained for one major release. */
LegacyNeutralAlias::register(TenantInactive::class, 'Nvl\\Tenancy\\Exceptions\\TenantInactive');
