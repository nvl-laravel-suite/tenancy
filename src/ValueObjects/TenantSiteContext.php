<?php

declare(strict_types=1);

namespace Nvl\Tenancy\ValueObjects;

use Nvl\Support\Tenancy\ValueObjects\TenantSiteContext;
use Nvl\Tenancy\Support\LegacyNeutralAlias;

/** @deprecated Use \Nvl\Support\Tenancy\ValueObjects\TenantSiteContext; retained for one major release. */
LegacyNeutralAlias::register(TenantSiteContext::class, 'Nvl\\Tenancy\\ValueObjects\\TenantSiteContext');
