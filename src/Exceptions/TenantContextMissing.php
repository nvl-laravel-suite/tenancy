<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Exceptions;

use Nvl\Support\Tenancy\Exceptions\TenantContextMissing;
use Nvl\Tenancy\Support\LegacyNeutralAlias;

/** @deprecated Use \Nvl\Support\Tenancy\Exceptions\TenantContextMissing; retained for one major release. */
LegacyNeutralAlias::register(TenantContextMissing::class, 'Nvl\\Tenancy\\Exceptions\\TenantContextMissing');
