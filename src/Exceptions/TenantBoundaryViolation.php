<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Exceptions;

use Nvl\Support\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Support\LegacyNeutralAlias;

/** @deprecated Use \Nvl\Support\Tenancy\Exceptions\TenantBoundaryViolation; retained for one major release. */
LegacyNeutralAlias::register(TenantBoundaryViolation::class, 'Nvl\\Tenancy\\Exceptions\\TenantBoundaryViolation');
