<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Contracts;

use Nvl\Support\Tenancy\Contracts\TenantContext;
use Nvl\Tenancy\Support\LegacyNeutralAlias;

/** @deprecated Use \Nvl\Support\Tenancy\Contracts\TenantContext; retained for one major release. */
LegacyNeutralAlias::register(TenantContext::class, 'Nvl\\Tenancy\\Contracts\\TenantContext');
