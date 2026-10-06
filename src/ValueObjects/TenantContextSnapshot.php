<?php

declare(strict_types=1);

namespace Nvl\Tenancy\ValueObjects;

use Nvl\Support\Tenancy\ValueObjects\TenantContextSnapshot;
use Nvl\Tenancy\Support\LegacyNeutralAlias;

/** @deprecated Use \Nvl\Support\Tenancy\ValueObjects\TenantContextSnapshot; retained for one major release. */
LegacyNeutralAlias::register(TenantContextSnapshot::class, 'Nvl\\Tenancy\\ValueObjects\\TenantContextSnapshot');
