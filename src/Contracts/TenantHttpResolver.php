<?php

declare(strict_types=1);

namespace Nvl\Tenancy\Contracts;

use Nvl\Support\Tenancy\Contracts\TenantHttpResolver;
use Nvl\Tenancy\Support\LegacyNeutralAlias;

/** @deprecated Use \Nvl\Support\Tenancy\Contracts\TenantHttpResolver; retained for one major release. */
LegacyNeutralAlias::register(TenantHttpResolver::class, 'Nvl\\Tenancy\\Contracts\\TenantHttpResolver');
